<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Console;

use Mfonte\ProxyCdn\Cache\CacheKeyResolver;
use Mfonte\ProxyCdn\Cache\CacheLocation;
use Mfonte\ProxyCdn\Cache\QueryStringPolicy;
use Mfonte\ProxyCdn\Config\Config;
use Mfonte\ProxyCdn\Security\HostAllowlist;
use Mfonte\ProxyCdn\Security\PathSanitizer;

/**
 * Turns the URLs given to the cache commands into what the proxy would
 * make of a request for them, with the rules of live traffic: the host
 * must be in PROXY_HOSTS (HostAllowlist), the path must pass PathSanitizer
 * and have a cacheable extension, and the query string goes through
 * QueryStringPolicy. An origin URL is mapped to the first proxy host of
 * that origin.
 */
final class ProxyUrls
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var HostAllowlist
     */
    private $hosts;

    /**
     * @var CacheKeyResolver
     */
    private $resolver;

    /**
     * @var PathSanitizer
     */
    private $sanitizer;

    /**
     * @var QueryStringPolicy
     */
    private $queryPolicy;

    public function __construct(Config $config, string $cacheDirectory)
    {
        $this->config = $config;
        $this->hosts = $config->getHostAllowlist();
        $this->resolver = new CacheKeyResolver($cacheDirectory);
        $this->sanitizer = new PathSanitizer();
        $this->queryPolicy = QueryStringPolicy::fromConfig($config);
    }

    /**
     * The proxy request of $url: its Host header, its request target as
     * given (path and raw query string), and its cache location; or the
     * reason it is refused.
     *
     * @return array{string, string, CacheLocation}|string
     */
    public function locate(string $url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return 'not an absolute http or https URL';
        }
        $host = $this->hosts->match($parts['host']);
        if ($host === null) {
            return 'the host is not in PROXY_HOSTS';
        }
        $rawPath = $parts['path'] ?? '/';
        $path = $this->sanitizer->sanitize($rawPath);
        if ($path === null) {
            return 'the path is refused';
        }
        if (!$this->config->isCacheableExtension(strtolower(pathinfo($path, PATHINFO_EXTENSION)))) {
            return 'the extension is not in CACHEABLE_EXTENSIONS';
        }
        $rawQuery = $parts['query'] ?? '';
        $query = $this->queryPolicy->apply($rawQuery);
        if ($query === null) {
            return 'the query string is refused (QUERY_STRING_MODE=reject)';
        }
        $httpHost = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return [$httpHost, $rawPath . ($rawQuery === '' ? '' : '?' . $rawQuery), $this->resolver->resolve($host, $path, $query->getCanonical())];
    }

    /**
     * $url on the proxy: unchanged when its host is in PROXY_HOSTS; on the
     * first proxy host of the origin when it is a URL of an origin, keeping
     * the path below the origin URL and the query string; null otherwise.
     * Only the scheme, host and port are compared.
     */
    public function toProxyUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if ($this->hosts->match($parts['host']) !== null) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        $tail = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        foreach ($this->hosts->getHosts() as $host) {
            $origin = (string) $this->hosts->originOf($host);
            $originParts = parse_url($origin);
            if (!is_array($originParts) || strtolower($originParts['scheme'] ?? '') !== strtolower((string) $parts['scheme'])
                || strtolower($originParts['host'] ?? '') !== strtolower($parts['host']) || ($originParts['port'] ?? null) !== ($parts['port'] ?? null)) {
                continue;
            }
            $base = rtrim($originParts['path'] ?? '', '/');
            if ($base !== '' && strpos($path, $base . '/') !== 0) {
                continue;
            }

            return 'https://' . $host . substr($tail, strlen($base));
        }

        return null;
    }
}
