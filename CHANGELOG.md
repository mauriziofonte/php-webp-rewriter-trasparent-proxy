# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions are numbered major.minor.patch.

## [Unreleased]

The changes below make up the 3.0.0 release.

A rewrite of the proxy. It changes the cache layout, the configuration and the purge mechanism: read [UPGRADING.md](UPGRADING.md) before upgrading from 2.x.

### Added

- Application code in `src/` under the `Mfonte\ProxyCdn` namespace, with `ProxyKernel` and `EdgeKernel`, which turn a Symfony `Request` into a `Response` and can be mounted in another application.
- nginx as a first-class web server, with the configuration in `config/nginx/`, next to Apache (`public/.htaccess` and `config/apache/proxy-cdn.conf`). Both serve every cache hit without PHP, including canonical query strings, variants and encodings.
- Several origins by `Host` header: `PROXY_HOSTS`, with a cache directory per host; any other host gets `421`.
- Content negotiation at the same URL: AVIF, then WEBP, then the original, from `Accept`, with `Vary: Accept`; Brotli and gzip encodings of text assets (`PRECOMPRESS`), from `Accept-Encoding`.
- Image conversion with `IMAGE_CONVERTER` (`none`, `local` with `mfonte/secure-image-converter`, `remote` with the conversion API v2), `IMAGE_FORMATS`, `IMAGE_CONVERSION_TIMEOUT`, `IMAGE_API_URL`, `IMAGE_API_KEY`, `IMAGE_API_CA_FILE` and `IMAGE_API_FALLBACK`. Variants are made after the response, verified before they are stored, and published with a pending tier.
- Signed resize URLs (`/_resize/<width>/<signature>/<path>`) with `RESIZE_WIDTHS` and `RESIZE_KEY`.
- HTTP cache semantics of a shared cache (RFC 9111): a managed tier for short-lived entries, revalidation with the origin, `stale-while-revalidate` and `stale-if-error` (`CACHE_REVALIDATE_BELOW`, `STALE_WHILE_REVALIDATE`, `STALE_IF_ERROR`); `no-store` and `private` responses are streamed without being stored.
- Request coalescing: concurrent misses of one entry cause one origin fetch, also when the origin fails or redirects.
- A circuit breaker per origin (`CIRCUIT_BREAKER_THRESHOLD`) and a bounded negative cache in `var/`.
- Authenticated `PURGE` with Bearer or HMAC keys scoped per origin (`PURGE_KEYS`); a purge without a query string also removes the entries of every query string of the path.
- A query-string allowlist (`QUERY_STRING_ALLOWLIST`, `QUERY_STRING_MODE`).
- `TRUSTED_PROXIES` for `X-Forwarded-Proto` and `X-Forwarded-For`, in PHP and in both web server configurations.
- `SENDFILE` (`x-accel-redirect`, `x-sendfile`), `UPSTREAM_MAX_SIZE` and `UPSTREAM_USER_AGENT`.
- Range requests, conditional requests and streaming of origin bodies to disk.
- `bin/proxy-cdn` with `check-env`, `cache:purge`, `cache:warmup`, `cache:evict` and `cache:migrate-2x`.
- `GET /healthz`, Prometheus `GET /metrics` (`METRICS_KEYS`, `METRICS_STORE`), JSON logs with a request ID (`LOG_FILE`, `LOG_LEVEL`, `X-Request-Id`).
- A miss rate limit per client (`MISS_RATE_LIMIT`, `MISS_RATE_LIMIT_BURST`) and `103 Early Hints` where the server API supports them (`EARLY_HINTS`).
- Purge fan-out to the other instances of a cluster (`CLUSTER_PEERS`, `CLUSTER_CA_FILE`, `CLUSTER_TIMEOUT`), with the example in `docker/cluster/`.
- LRU eviction to a size cap (`CACHE_MAX_SIZE`).
- OPcache settings and a preload script in `config/php/`.
- A compiled configuration (`.config.cache.php`), recompiled when its sources change.
- A test suite (unit, functional, integration, contract), static analysis, Apache and nginx integration tests, a Laravel embedding check and a latency suite, in CI on PHP 7.2 to 8.5.

### Changed

- The cache lives in `public/_cache/<host>/`, with variants, encodings and query-string buckets in dot directories; the state lives in `var/`.
- The configuration is read from the environment first and from `.env` second, `.env` is optional, and every value is validated before a request is answered.
- `UPSTREAM_VERIFY_TLS` defaults to `true`.
- `NETWORK_ERROR_TTL` is the open time of the circuit breaker of an origin.
- Error responses use the status that matches the failure (`400`, `403`, `405`, `410`, `421`, `500`, `502`, `503`) instead of `404` for most of them; an invalid configuration answers `503`.
- Origin `403` answers and redirects are passed on, and the other `4xx` answers but `429` are answered `502`, instead of `503`; none of them opens the circuit breaker any more.
- `OPTIONS` answers a valid CORS preflight for anonymous requests; methods other than `GET`, `HEAD`, `OPTIONS` and `PURGE` get `405`.
- Media types follow the registered types (`text/javascript`, `font/*`, `image/vnd.microsoft.icon`), the same in PHP, Apache and nginx.
- `X-Cache-Status` reports what happened (`HIT`, `MISS`, `REVALIDATED`, `STALE`, `BYPASS`) and is sent on assets only.
- The HTTPS redirect believes `X-Forwarded-Proto` only from trusted proxies.
- Request paths are decoded once, and paths that could name another file are refused.
- Stylesheets are stored and served as the origin sent them.
- PHP 7.2.5 or later is required.

### Removed

- The 2.x configuration keys `IMAGE_CONVERSION_MODE`, `WEBP_API_SERVER` and `WEBP_API_KEY`, which are no longer read; `check-env` warns while they are set and explains how to move them to `IMAGE_CONVERTER`, `IMAGE_API_URL` and `IMAGE_API_KEY`.
- The anonymous `?purge=1` and `?purge_all=1`, which now answer `401`.
- The `301` redirect of raster URLs to `X.webp`; the `X.webp` URLs keep working through `LEGACY_WEBP_URLS`.
- `bootstrap.php`, `helpers/functions.php` and `scripts/check-env.php`.
- The `.404` and `.netfail` markers next to the cache files, and the `_qs/` query-string buckets.

### Security

- Only the hosts of `PROXY_HOSTS` reach the cache, so a random `Host` header creates nothing on disk.
- SVG responses carry a restrictive `Content-Security-Policy`, and every asset carries `X-Content-Type-Options: nosniff`.
- The compiled configuration is written with mode `0600`.
- Redirects to other hosts are followed only to public addresses, with the connection pinned to the checked address.

[Unreleased]: https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy/commits/HEAD
