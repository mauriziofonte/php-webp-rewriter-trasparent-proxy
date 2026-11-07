<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Exception\InvalidArgumentException;
use Mfonte\ProxyCdn\Exception\InvalidConfigurationException;
use Mfonte\ProxyCdn\Http\RequestId;
use Mfonte\ProxyCdn\Origin\CurlTransport;
use Mfonte\ProxyCdn\Origin\HttpClient;
use Mfonte\ProxyCdn\Origin\HttpClientFactory;
use Mfonte\ProxyCdn\Origin\HttpClientOptions;
use Mfonte\ProxyCdn\Origin\HttpOriginFetcher;
use Mfonte\ProxyCdn\Origin\HttpRequest;
use Mfonte\ProxyCdn\Origin\HttpResponse;
use Mfonte\ProxyCdn\Origin\MultiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * "cache:warmup": requests the URLs of a sitemap (<loc> and <image:loc>,
 * a sitemap index one level deep) or of a list (one URL per line) through
 * the proxy, so that the misses are fetched before the visitors ask.
 *
 * Every URL goes through the rules of live traffic before anything is
 * sent (ProxyUrls): an origin URL is mapped to its proxy host, a URL of
 * any other host is refused, and so are a refused path, an extension that
 * is not cacheable and a refused query string; the URLs of one entry are
 * requested once. The requests go to the proxy itself (its web server, or
 * the address of --via), never to the origin, so the origin fetches are
 * those of live traffic: same allowlist, SSRF and redirect rules, same
 * circuit breaker and miss rate limit. A sitemap given by URL must be on a
 * proxy host or an origin, and is fetched with the UPSTREAM_* settings.
 *
 * Requests run on curl_multi (MultiClient) with at most --concurrency at a
 * time, one after the other without ext-curl. The URLs left out are
 * listed; the command exits with 1 when a request does not get a 2xx.
 */
final class CacheWarmupCommand extends Command
{
    public const NAME = 'cache:warmup';

    public const DEFAULT_ACCEPT = 'image/avif,image/webp,*/*';

    public const MAX_CONCURRENCY = 32;

    /**
     * The child sitemaps of an index read at most.
     */
    public const MAX_SITEMAPS = 100;

    /**
     * The URLs requested in one curl_multi round.
     */
    private const BATCH = 256;

    /**
     * Failures and refusals listed at most.
     */
    private const MAX_LISTED = 20;

    /**
     * @var Installation
     */
    private $installation;

    /**
     * @var (callable(HttpClientOptions): HttpClient)|null
     */
    private $clientFactory;

    /**
     * The optional $clientFactory builds the client of the sitemap and of
     * the requests, for tests; null for CurlTransport on curl_multi, or
     * the stream transport.
     *
     * @param (callable(HttpClientOptions): HttpClient)|null $clientFactory
     */
    public function __construct(Installation $installation, ?callable $clientFactory = null)
    {
        $this->installation = $installation;
        $this->clientFactory = $clientFactory;
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Request the URLs of a sitemap or of a list through the proxy')
            ->addArgument('source', InputArgument::REQUIRED, 'A sitemap or a list of URLs: a local file, or the URL of a sitemap on the origin')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Requests at a time, 1 to ' . self::MAX_CONCURRENCY, '4')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Send the requests to this address[:port] of the proxy instead of the address of its host name')
            ->addOption('scheme', null, InputOption::VALUE_REQUIRED, 'The scheme of the proxy, https or http', 'https')
            ->addOption('accept', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The Accept header of the requests; repeat it to warm each variant', [self::DEFAULT_ACCEPT])
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'The most URLs read', '50000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->installation->loadConfig();
        } catch (InvalidConfigurationException $exception) {
            $output->writeln('<error>The configuration is invalid; run check-env.</error>');

            return 1;
        }
        $concurrency = filter_var($input->getOption('concurrency'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_CONCURRENCY]]);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $scheme = $input->getOption('scheme');
        /** @var list<string> $accepts */
        $accepts = (array) $input->getOption('accept');
        $via = $input->getOption('via');
        if (!is_int($concurrency) || !is_int($limit) || !is_string($scheme) || !in_array($scheme, ['https', 'http'], true)) {
            $output->writeln('<error>--concurrency must be 1 to ' . self::MAX_CONCURRENCY . ', --limit at least 1 and --scheme https or http.</error>');

            return 1;
        }
        $requestId = RequestId::generate();
        try {
            $options = HttpOriginFetcher::optionsFromConfig($config)->withConnectTo(null)->withRedirects(false);
            $proxyOptions = (new HttpClientOptions())
                ->withTimeouts($options->getConnectTimeout(), $options->getTimeout())
                ->withMaxBodySize($options->getMaxBodySize())
                ->withConnectTo(is_string($via) ? $via : null);
            foreach ($accepts as $accept) {
                new HttpRequest('https://localhost/', ['Accept' => $accept]);
            }
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '.</error>');

            return 1;
        }

        $proxyUrls = new ProxyUrls($config, $this->installation->getCacheDirectory());
        $source = $input->getArgument('source');
        $source = is_string($source) ? $source : '';
        $document = $this->read($source, $proxyUrls, $this->createClient(HttpOriginFetcher::optionsFromConfig($config)), $output);
        if ($document === null) {
            return 1;
        }
        $urls = self::urlsOf($document, $limit);
        if (self::isSitemapIndex($document)) {
            $children = $urls;
            $urls = [];
            foreach (array_slice($children, 0, self::MAX_SITEMAPS) as $child) {
                $childDocument = $this->read($child, $proxyUrls, $this->createClient(HttpOriginFetcher::optionsFromConfig($config)), $output);
                $urls = array_merge($urls, $childDocument === null ? [] : self::urlsOf($childDocument, $limit - count($urls)));
                if (count($urls) >= $limit) {
                    break;
                }
            }
        }

        list($targets, $refused) = $this->targets($urls, $proxyUrls, $scheme, $accepts, $requestId);
        foreach (array_slice($refused, 0, self::MAX_LISTED) as $line) {
            $output->writeln('<comment>skipped ' . OutputFormatter::escape($line) . '</comment>');
        }
        $output->writeln(sprintf('%d URLs read, %d requests to send, %d skipped.', count($urls), count($targets), count($refused)));

        list($summary, $failures) = $this->send($targets, $proxyOptions, $concurrency);
        ksort($summary);
        foreach ($summary as $line => $count) {
            $output->writeln(sprintf('  %s: %d', $line, $count));
        }
        foreach (array_slice($failures, 0, self::MAX_LISTED) as $line) {
            $output->writeln('<error>✗ ' . OutputFormatter::escape($line) . '</error>');
        }
        $this->installation->createLogger($config, $requestId)->notice('cache warmed up', [
            'requests' => count($targets),
            'failed' => count($failures),
            'skipped' => count($refused),
        ]);

        return $failures === [] ? 0 : 1;
    }

    /**
     * The request of each distinct entry and Accept header, and the refused
     * URLs with their reason.
     *
     * @param list<string> $urls
     * @param list<string> $accepts
     *
     * @return array{list<HttpRequest>, list<string>}
     */
    private function targets(array $urls, ProxyUrls $proxyUrls, string $scheme, array $accepts, string $requestId): array
    {
        $requests = [];
        $refused = [];
        foreach ($urls as $url) {
            $proxyUrl = $proxyUrls->toProxyUrl($url);
            $located = $proxyUrl === null ? 'neither a proxy host nor an origin' : $proxyUrls->locate($proxyUrl);
            if (is_string($located)) {
                $refused[] = $url . ': ' . $located;
                continue;
            }
            list($httpHost, $target, $location) = $located;
            foreach ($accepts as $accept) {
                $key = $location->getKey() . "\n" . $accept;
                if (!isset($requests[$key])) {
                    $requests[$key] = new HttpRequest($scheme . '://' . $httpHost . $target, ['Accept' => $accept, 'X-Request-Id' => $requestId]);
                }
            }
        }

        return [array_values($requests), $refused];
    }

    /**
     * Sends the requests and counts the answers by status and
     * X-Cache-Status; lists the failures.
     *
     * @param list<HttpRequest> $requests
     *
     * @return array{array<string, int>, list<string>}
     */
    private function send(array $requests, HttpClientOptions $options, int $concurrency): array
    {
        $client = $this->createClient($options);
        $multi = $client instanceof CurlTransport && MultiClient::isAvailable() ? new MultiClient($client, $concurrency) : null;
        $summary = [];
        $failures = [];
        foreach (array_chunk($requests, self::BATCH) as $batch) {
            if ($multi !== null) {
                $responses = $multi->sendAll($batch);
            } else {
                $responses = [];
                foreach ($batch as $index => $request) {
                    $responses[$index] = $client->send($request);
                }
            }
            foreach ($responses as $index => $response) {
                $status = $response->getStatus();
                $cache = $response->getHeader('x-cache-status');
                $line = $status === 0 ? $response->getOutcome() : $status . ' ' . ($cache === null ? '-' : $cache);
                $summary[$line] = ($summary[$line] ?? 0) + 1;
                if ($status < 200 || $status > 299) {
                    $failures[] = $batch[$index]->getUrl() . ': ' . ($status === 0 ? $response->getOutcome() : (string) $status);
                }
                if ($response->getBody() !== null) {
                    $response->getBody()->delete();
                }
            }
        }

        return [$summary, $failures];
    }

    /**
     * The contents of a local file, or of a sitemap URL on a proxy host or
     * an origin; null, reported, when it cannot be read.
     */
    private function read(string $source, ProxyUrls $proxyUrls, HttpClient $client, OutputInterface $output): ?string
    {
        if (preg_match('#\Ahttps?://#i', $source) !== 1) {
            $contents = is_file($source) ? @file_get_contents($source) : false;
            if ($contents === false) {
                $output->writeln('<error>' . OutputFormatter::escape($source) . ' cannot be read.</error>');

                return null;
            }

            return $contents;
        }
        if ($proxyUrls->toProxyUrl($source) === null) {
            $output->writeln('<error>' . OutputFormatter::escape($source) . ' is neither on a proxy host nor on an origin.</error>');

            return null;
        }
        try {
            $response = $client->send(new HttpRequest($source, ['Accept' => 'application/xml, text/xml, text/plain']));
        } catch (InvalidArgumentException $exception) {
            $response = null;
        }
        $body = $response === null || $response->getOutcome() !== HttpResponse::OUTCOME_SUCCESS ? null : $response->getBody();
        $contents = $body === null ? null : $body->getContents();
        if ($contents === null) {
            $output->writeln('<error>' . OutputFormatter::escape($source) . ' cannot be fetched' . ($response === null ? '' : ' (' . ($response->getStatus() === 0 ? $response->getOutcome() : (string) $response->getStatus()) . ')') . '.</error>');

            return null;
        }

        return $contents;
    }

    /**
     * The URLs of a sitemap (<loc>, <image:loc>) or of a list (one per
     * line, "#" for a comment), at most $limit.
     *
     * @return list<string>
     */
    private static function urlsOf(string $document, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $urls = [];
        if (preg_match('/<(?:[A-Za-z0-9_-]+:)?(?:urlset|sitemapindex)\b/', $document) === 1) {
            preg_match_all('#<(?:image:)?loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</(?:image:)?loc>#s', $document, $matches);
            foreach ($matches[1] as $url) {
                $urls[] = html_entity_decode(trim($url), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        } else {
            foreach (preg_split('/\R/', $document) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#') {
                    $urls[] = $line;
                }
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, $limit);
    }

    private static function isSitemapIndex(string $document): bool
    {
        return preg_match('/<(?:[A-Za-z0-9_-]+:)?sitemapindex\b/', $document) === 1;
    }

    private function createClient(HttpClientOptions $options): HttpClient
    {
        return $this->clientFactory === null ? HttpClientFactory::create($options) : ($this->clientFactory)($options);
    }
}
