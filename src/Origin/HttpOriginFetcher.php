<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Origin;

use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * The OriginFetcher of the proxy, on HttpClient: one GET with the request
 * headers of 2.x (the Referer the kernel passes, "Pragma: no-cache" and
 * "Cache-Control: no-cache") and the User-Agent of the client.
 *
 * As in 2.x only a 200 is a success, a 404 is logged as information and
 * every other failure as an error. Each outcome of the client keeps its
 * own failure reason (finding P-13), and the body is read from the
 * temporary file of the client, which the size limit bounds (finding
 * P-17); the kernel still receives it as a string until PRX-7.
 */
final class HttpOriginFetcher implements OriginFetcher
{
    /**
     * Headers 2.x added to every origin request.
     */
    private const REQUEST_HEADERS = ['pragma' => 'no-cache', 'cache-control' => 'no-cache'];

    /**
     * The failure reason of each outcome of the client but a 200.
     */
    private const REASONS = [
        HttpResponse::OUTCOME_SUCCESS => OriginResponse::FAILURE_OTHER,
        HttpResponse::OUTCOME_NOT_MODIFIED => OriginResponse::FAILURE_OTHER,
        HttpResponse::OUTCOME_REDIRECT => OriginResponse::FAILURE_REDIRECT,
        HttpResponse::OUTCOME_FORBIDDEN => OriginResponse::FAILURE_FORBIDDEN,
        HttpResponse::OUTCOME_NOT_FOUND => OriginResponse::FAILURE_NOT_FOUND,
        HttpResponse::OUTCOME_GONE => OriginResponse::FAILURE_GONE,
        HttpResponse::OUTCOME_CLIENT_ERROR => OriginResponse::FAILURE_CLIENT_ERROR,
        HttpResponse::OUTCOME_SERVER_ERROR => OriginResponse::FAILURE_SERVER_ERROR,
        HttpResponse::OUTCOME_TOO_LARGE => OriginResponse::FAILURE_TOO_LARGE,
        HttpResponse::OUTCOME_TIMEOUT => OriginResponse::FAILURE_TIMEOUT,
        HttpResponse::OUTCOME_DNS => OriginResponse::FAILURE_DNS,
        HttpResponse::OUTCOME_CONNECT => OriginResponse::FAILURE_CONNECT,
        HttpResponse::OUTCOME_TLS => OriginResponse::FAILURE_TLS,
        HttpResponse::OUTCOME_NETWORK => OriginResponse::FAILURE_NETWORK,
        HttpResponse::OUTCOME_OTHER => OriginResponse::FAILURE_OTHER,
    ];

    /**
     * Redirects followed when UPSTREAM_FOLLOW_REDIRECTS is on, as in 2.x.
     */
    private const MAX_REDIRECTS = 10;

    /**
     * @var HttpClient
     */
    private $client;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(HttpClient $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * A fetcher on the transport HttpClientFactory picks, with the settings
     * of optionsFromConfig().
     */
    public static function fromConfig(Config $config, LoggerInterface $logger): self
    {
        return new self(HttpClientFactory::create(self::optionsFromConfig($config)), $logger);
    }

    /**
     * The client settings of the UPSTREAM_* keys and SOURCE_ORIGIN_SERVER.
     * A connect timeout above the total timeout is lowered to it.
     */
    public static function optionsFromConfig(Config $config): HttpClientOptions
    {
        $timeout = (float) $config->getUpstreamTimeout();
        $userAgent = $config->getUpstreamUserAgent();

        return (new HttpClientOptions())
            ->withTimeouts(min((float) $config->getUpstreamConnectTimeout(), $timeout), $timeout)
            ->withTlsVerification($config->shouldVerifyUpstreamTls())
            ->withRedirects($config->shouldFollowUpstreamRedirects(), self::MAX_REDIRECTS)
            ->withMaxBodySize($config->getUpstreamMaxSize())
            ->withUserAgent($userAgent ?? HttpClientOptions::DEFAULT_USER_AGENT)
            ->withConnectTo($config->getSourceOriginServer());
    }

    public function fetch(OriginRequest $request): OriginResponse
    {
        $url = $request->getUrl();
        try {
            $httpRequest = new HttpRequest($url, $request->getHeaders() + self::REQUEST_HEADERS);
        } catch (InvalidArgumentException $exception) {
            return $this->transportFailure($url, OriginResponse::FAILURE_OTHER, 0, 0, $exception->getMessage());
        }

        $response = $this->client->send($httpRequest);
        $outcome = $response->getOutcome();
        $status = $response->getStatus();

        if ($outcome === HttpResponse::OUTCOME_SUCCESS && $status === 200) {
            $body = $response->getBody();
            $contents = $body === null ? null : $body->getContents();
            if ($body === null || $contents === null) {
                return $this->transportFailure($url, OriginResponse::FAILURE_OTHER, $status, 0, 'The response body cannot be read');
            }
            $body->delete();

            return OriginResponse::success($response->getHeaders(), $contents);
        }

        if ($outcome === HttpResponse::OUTCOME_NOT_FOUND) {
            $this->logger->info('upstream 404', ['url' => $url]);

            return OriginResponse::failure(OriginResponse::FAILURE_NOT_FOUND, 404);
        }

        if ($outcome === HttpResponse::OUTCOME_REDIRECT) {
            $answeredBy = $response->getEffectiveUrl();
            $location = $response->getRedirectLocation();
            $target = $location === null ? null : RedirectTarget::resolve($answeredBy !== '' ? $answeredBy : $url, $location);
            $this->logger->notice('upstream redirect', ['url' => $url, 'response_code' => $status, 'location' => $target]);

            return OriginResponse::redirect($status, $target);
        }

        $reason = self::REASONS[$outcome] ?? OriginResponse::FAILURE_OTHER;
        if ($response->isTransportFailure()) {
            return $this->transportFailure($url, $reason, $status, $response->getErrorCode(), $response->getError());
        }

        $this->logger->error('upstream non-200', ['url' => $url, 'response_code' => $status, 'reason' => $reason]);

        return OriginResponse::failure($reason, $status);
    }

    private function transportFailure(string $url, string $reason, int $status, int $errorCode, string $error): OriginResponse
    {
        $this->logger->error('upstream fetch failed', [
            'url' => $url,
            'reason' => $reason,
            'transport_error_code' => $errorCode,
            'transport_error' => $error,
            'response_code' => $status,
        ]);

        return OriginResponse::failure($reason, $status, $errorCode, $error);
    }
}
