<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Cluster;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClient;
use Mfonte\ProxyCdn\Origin\HttpClientFactory;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\MultiClient;
use Mfonte\ProxyCdn\Security\HostAllowlist;
use Mfonte\ProxyCdn\Security\PurgeAuthenticator;
use Psr\Log\LoggerInterface;

/**
 * Sends a purge that this instance accepted to the other instances of the
 * cluster (CLUSTER_PEERS), so that each removes its own copy.
 *
 * Each peer gets "PURGE <peer base URL><request target>" with the Host
 * header of the original purge, the request ID, "X-Purge-Fanout: 1" and
 * an HMAC of PurgeAuthenticator::fanoutMessage() under the first purge key
 * of the origin (PurgeAuthenticator::secretFor()): every instance must
 * hold the same PURGE_KEYS. A peer accepts such a purge with the HMAC
 * only and never fans it out again, so purges cannot loop.
 *
 * Peers are reached over https only, their certificate always verified
 * (against CLUSTER_CA_FILE when set), without redirects, with a connect
 * timeout of at most one second and CLUSTER_TIMEOUT seconds for the whole
 * request. With ext-curl up to MAX_CONCURRENCY peers are asked at once
 * (MultiClient), so a fan-out to n peers takes at most
 * ceil(n / MAX_CONCURRENCY) times CLUSTER_TIMEOUT (CLUSTER_TIMEOUT up to
 * 16 peers); without it the peers are asked in turn, n times
 * CLUSTER_TIMEOUT at most. A failing peer is logged and
 * counted, and never undoes or delays the local purge: the caller runs
 * the fan-out after the response is sent.
 */
final class PeerNotifier
{
    /**
     * The largest peer answer read, in bytes: a purge answer is a line of JSON.
     */
    public const MAX_ANSWER_BYTES = 65536;

    /**
     * The most peers asked at a time.
     */
    public const MAX_CONCURRENCY = 16;

    /**
     * @var list<string>
     */
    private $peers;

    /**
     * @var PurgeAuthenticator
     */
    private $authenticator;

    /**
     * @var HostAllowlist
     */
    private $hosts;

    /**
     * @var HttpClient
     */
    private $client;

    /**
     * @var MultiClient|null
     */
    private $multi;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param list<string>     $peers peer base URLs ("https://host[:port]", see OperationsConfig::peerBase())
     * @param MultiClient|null $multi asks every peer at once; null to ask them one after the other with $client
     */
    public function __construct(
        array $peers,
        PurgeAuthenticator $authenticator,
        HostAllowlist $hosts,
        HttpClient $client,
        ?MultiClient $multi,
        LoggerInterface $logger
    ) {
        $this->peers = $peers;
        $this->authenticator = $authenticator;
        $this->hosts = $hosts;
        $this->client = $client;
        $this->multi = $multi;
        $this->logger = $logger;
    }

    /**
     * The notifier of the configuration; null when CLUSTER_PEERS is empty,
     * so that a single instance sends nothing.
     */
    public static function fromConfig(Config $config, LoggerInterface $logger): ?self
    {
        $operations = $config->getOperations();
        $peers = $operations->getClusterPeers();
        if ($peers === []) {
            return null;
        }
        $timeout = (float) $operations->getClusterTimeout();
        $options = (new HttpClientOptions())
            ->withTimeouts(min(1.0, $timeout), $timeout)
            ->withTlsVerification(true, $operations->getClusterCaFile())
            ->withRedirects(false)
            ->withMaxBodySize(self::MAX_ANSWER_BYTES)
            ->withCompression(false);
        $client = HttpClientFactory::create($options);
        $multi = $client instanceof CurlTransport && MultiClient::isAvailable()
            ? new MultiClient($client, min(self::MAX_CONCURRENCY, count($peers)))
            : null;

        return new self($peers, PurgeAuthenticator::fromConfig($config), $config->getHostAllowlist(), $client, $multi, $logger);
    }

    /**
     * @return list<string>
     */
    public function getPeers(): array
    {
        return $this->peers;
    }

    /**
     * Sends the purge of $requestTarget on $httpHost to every peer and
     * returns whether each one confirmed it (200), keyed by peer base URL.
     * Never throws: a peer that cannot be reached is a false.
     *
     * @param string $httpHost      the Host header of the purge, with its port when it has one
     * @param string $requestTarget the path and query string of the purge, as received
     * @param string $requestId     the request ID, sent along for the logs of the peers
     *
     * @return array<string, bool>
     */
    public function notify(string $httpHost, string $requestTarget, string $requestId): array
    {
        $httpHost = strtolower($httpHost);
        $host = $this->hosts->match($httpHost);
        $origin = $host === null ? null : $this->hosts->originOf($host);
        $secret = $origin === null ? null : $this->authenticator->secretFor((string) parse_url($origin, PHP_URL_HOST));
        if ($secret === null || $requestTarget === '' || $requestTarget[0] !== '/') {
            $this->logger->warning('purge not fanned out: no purge key covers the host', ['host' => $httpHost]);

            return array_fill_keys($this->peers, false);
        }

        $timestamp = (string) time();
        $headers = [
            'Host' => $httpHost,
            PurgeAuthenticator::FANOUT_HEADER => '1',
            PurgeAuthenticator::TIMESTAMP_HEADER => $timestamp,
            PurgeAuthenticator::SIGNATURE_HEADER => hash_hmac('sha256', PurgeAuthenticator::fanoutMessage($httpHost, $requestTarget, $timestamp, ''), $secret),
            'X-Request-Id' => $requestId,
        ];
        $requests = [];
        foreach ($this->peers as $peer) {
            try {
                $requests[$peer] = new HttpRequest($peer . $requestTarget, $headers, HttpRequest::METHOD_PURGE);
            } catch (InvalidArgumentException $exception) {
                $this->logger->warning('purge not fanned out to a peer: invalid request', ['peer' => $peer]);
            }
        }

        $responses = $this->send($requests);
        $results = [];
        foreach ($this->peers as $peer) {
            $response = $responses[$peer] ?? null;
            $results[$peer] = $response !== null && $response->getStatus() === 200;
            if ($response !== null && $response->getBody() !== null) {
                $response->getBody()->delete();
            }
            if (!$results[$peer]) {
                $this->logger->warning('peer purge failed', [
                    'peer' => $peer,
                    'host' => $httpHost,
                    'target' => $requestTarget,
                    'status' => $response === null ? 0 : $response->getStatus(),
                    'outcome' => $response === null ? HttpResponse::OUTCOME_OTHER : $response->getOutcome(),
                ]);
            }
        }

        return $results;
    }

    /**
     * @param array<string, HttpRequest> $requests
     *
     * @return array<string, HttpResponse>
     */
    private function send(array $requests): array
    {
        if ($this->multi !== null) {
            return $this->multi->sendAll($requests);
        }
        $responses = [];
        foreach ($requests as $peer => $request) {
            $responses[$peer] = $this->client->send($request);
        }

        return $responses;
    }
}
