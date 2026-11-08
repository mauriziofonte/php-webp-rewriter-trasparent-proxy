# Configuration reference

This page lists every configuration key of the proxy, with its default, its accepted values and its meaning. The keys and the rules come from `src/Config/ConfigLoader.php`, `src/Config/ConfigValidator.php` and `src/Config/OperationsConfig.php`; [`.env.example`](../.env.example) is a starting point for a new installation.

- [How the configuration is read](#how-the-configuration-is-read)
- [The compiled configuration](#the-compiled-configuration)
- [Origin and hosts](#origin-and-hosts)
- [Cache](#cache)
- [Origin requests](#origin-requests)
- [Query strings](#query-strings)
- [Purge and trusted proxies](#purge-and-trusted-proxies)
- [Serving](#serving)
- [Image conversion and resizing](#image-conversion-and-resizing)
- [Logging and metrics](#logging-and-metrics)
- [Miss rate limit and Early Hints](#miss-rate-limit-and-early-hints)
- [Cluster and eviction](#cluster-and-eviction)
- [PHP runtime](#php-runtime)
- [Keys removed in 3.0](#keys-removed-in-30)

## How the configuration is read

1. A variable of the PHP environment that is set and not empty wins.
2. Otherwise the value in `.env` at the project root is used. The file is optional when the environment carries the whole configuration.
3. An empty value and the literal `null` mean "not set": the default of the key applies.

`.env` is parsed with `vlucas/phpdotenv` into an array; the process environment, `$_ENV` and `$_SERVER` are never modified.

Every value is validated before the proxy answers a request, with the same rules that `bin/proxy-cdn check-env` applies. An invalid configuration answers every request with `503` and the error code `ENV_FILE_LOAD_ERR`, and logs the errors to `logs/proxy.log`. Run `composer check-env` (or `php bin/proxy-cdn check-env`) after every change: it prints each error and warning, and exits with a non-zero status on any error.

Boolean keys accept `true`, `false`, `1` and `0`, in any letter case. Integer keys accept decimal digits only. A list is comma-separated, and the spaces around each entry are ignored.

## The compiled configuration

The first request after a change compiles the configuration into `.config.cache.php` at the project root, so that `.env` is not parsed and validated on every request. The file is:

- written atomically (temporary file, then `rename()`) with mode `0600`, because it holds every secret, including those that come only from the environment;
- recompiled automatically on the next request whenever `.env` or a configuration variable of the environment changes. Nothing has to be deleted by hand;
- ignored, and replaced, when other users can read it;
- skipped when the project root is not writable: the configuration is then read and validated on every request.

Run the proxy and every command that loads the configuration as the same user (the PHP-FPM pool user). Otherwise each of them compiles the file again for itself.

## Origin and hosts

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `SOURCE_ORIGIN` | required | `http://` or `https://` URL | The origin that missing assets are fetched from, for every host of `PROXY_HOSTS` that does not name its own. |
| `PROXY_HOSTS` | required | `host` or `host=origin URL`, comma-separated | The host names the proxy answers for. `cdn.example.com` fetches from `SOURCE_ORIGIN`; `cdn.example.com=https://origin.example.com` fetches from its own origin. Each host has its own cache directory, `public/_cache/<host>/`. A name matches only itself, in any letter case, with or without a trailing dot and a port; there are no wildcards, and an IPv6 literal is not accepted. Any other `Host` header is answered `421 Unknown host`. |
| `SOURCE_ORIGIN_SERVER` | not set | `address`, `address:port` or `[IPv6 address]:port` | Connects to this address instead of the address of the origin host name, which is still used for the `Host` header and TLS. It bypasses DNS and therefore any CDN or WAF in front of the origin. It cannot be combined with `PROXY_HOSTS` entries that name another origin. |
| `CACHEABLE_EXTENSIONS` | required | comma-separated extensions | The extensions the proxy serves; any other extension is answered `404`. `.env.example` lists `css,js,jpg,jpeg,png,gif,bmp,webp,ttf,woff,woff2,otf,eot,svg,ico`. `check-env` warns about `html`, `htm`, `php`, `xml` and `json`, whose responses may depend on a session. |

## Cache

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `CACHE_EXPIRY` | `86400` | `0` or more | The `max-age` of the responses PHP sends, with `public` and `must-revalidate`. `.env.example` sets `31536000`, the value that [`public/.htaccess`](../public/.htaccess) and [`config/nginx/proxy-cdn-headers.conf`](../config/nginx/proxy-cdn-headers.conf) send on the hits the web server serves; `check-env` warns when they differ. |
| `CACHE_REVALIDATE_BELOW` | `3600` | `0` to `31536000` | An entry whose origin lifetime (`s-maxage`, `max-age` or `Expires`) is shorter goes to the managed tier, which PHP serves and revalidates. `0` keeps every entry in the web-server tier until it is purged. See [Cache tiers](caching.md#cache-tiers). |
| `STALE_WHILE_REVALIDATE` | `60` | `0` to `86400` | Seconds an expired managed entry is still served while PHP revalidates it after the response, when the origin sends no `stale-while-revalidate`. |
| `STALE_IF_ERROR` | `86400` | `0` to `604800` | Seconds an expired managed entry is still served while the origin fails to revalidate it, when the origin sends no `stale-if-error`. |
| `NEGATIVE_CACHE_TTL` | `300` | `0` to `86400` | Seconds a `404` or `410` of the origin is remembered and answered without asking the origin again. |
| `NETWORK_ERROR_TTL` | `30` | `0` to `3600` | Seconds the circuit breaker of an origin stays open; also the `Retry-After` of a `503`. `0` disables the breaker. |
| `CIRCUIT_BREAKER_THRESHOLD` | `5` | `1` to `1000` | Consecutive unavailability failures of an origin (5xx, 429, timeout, DNS, connection, TLS or network error) that open its breaker. |
| `PRECOMPRESS` | `true` | boolean | Stores a gzip (ext-zlib) and a Brotli (ext-brotli) copy of each text, SVG, icon and font entry between 256 bytes and 16 MiB, when the copy is smaller. The web server sends them to the clients that accept them. |
| `LEGACY_WEBP_URLS` | `true` | boolean | Answers a request for `X.webp`, where 2.x redirected clients, with the WEBP variant of the raster entry `X.jpg`, `X.png` and so on, unless an `X.webp` entry exists. PHP answers these URLs on every request. Turn it off once no client asks for them. |

## Origin requests

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `UPSTREAM_TIMEOUT` | `30` | `1` to `300` | Seconds a whole origin fetch may take. A transfer slower than 1024 bytes per second for 10 seconds is also aborted. |
| `UPSTREAM_CONNECT_TIMEOUT` | `5` | `1` to `60` | Seconds to connect to the origin (and to the conversion API). |
| `UPSTREAM_VERIFY_TLS` | `true` | boolean | Verifies the certificate and the host name of an https origin. 2.x did not verify; `check-env` prints a note while the key is unset and the origin uses https. |
| `UPSTREAM_FOLLOW_REDIRECTS` | `false` | boolean | `false`: a redirect of the origin to a URL below its own origin is passed to the client, rewritten to the proxy path; any other redirect is a `502`. `true`: the proxy follows up to 10 redirects itself; a redirect to another host is followed only when every address of that host is public, and the connection is pinned to the checked address. |
| `UPSTREAM_MAX_SIZE` | `67108864` (64 MiB) | `1` to `268435456` | The largest origin body; a larger one is a `502`. Also the largest converted image. |
| `UPSTREAM_USER_AGENT` | `mfonte-proxy-cdn/3 (+https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy)` | 1 to 512 printable ASCII characters | The `User-Agent` of origin and conversion API requests. |

## Query strings

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `QUERY_STRING_ALLOWLIST` | `v,ver,version,id` | parameter names, `*` or `none` | The query parameters that key the cache and reach the origin, compared by decoded name and letter case. `*` keeps every parameter (the 2.x behavior; `check-env` warns), `none` keeps none. `id` is the version parameter of Laravel Mix. |
| `QUERY_STRING_MODE` | `ignore` | `ignore` or `reject` | What happens to the other parameters: `ignore` drops them from the cache key and from the origin request; `reject` answers `400 Query parameter not allowed`. |

## Purge and trusted proxies

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `PURGE_KEYS` | not set | `scope=secret` pairs, comma-separated | The keys that authenticate a `PURGE`. The scope is the host name of an origin (the host of `SOURCE_ORIGIN` or of a `PROXY_HOSTS` origin) or `*` for every origin; the secret is 16 or more printable ASCII characters without spaces or commas. Not set: every purge is refused. See [Purge](../README.md#purge). |
| `TRUSTED_PROXIES` | not set | IP addresses and CIDR blocks, IPv4 or IPv6 | The reverse proxies whose `X-Forwarded-Proto` and `X-Forwarded-For` are believed, for the client address (logs, miss rate limit) and the scheme. Not set: nobody is trusted. The web server needs the same list for its HTTPS redirect: see [Trusted proxies](deployment.md#trusted-proxies-and-the-https-redirect). |

## Serving

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `SENDFILE` | `none` | `none`, `x-sendfile` or `x-accel-redirect` | How PHP sends a cache file it serves: `none` streams it from PHP; `x-sendfile` hands it to Apache `mod_xsendfile`; `x-accel-redirect` hands it to nginx. PHP hands a file over only when the web server sets the variable `PROXYCDN_SENDFILE` to the same mode, so a missing module never produces empty responses. See [Sending files](deployment.md#sending-files-from-the-web-server). |

## Image conversion and resizing

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `IMAGE_CONVERTER` | `none` | `none`, `local` or `remote` | Who makes the AVIF and WEBP variants of raster entries (`jpg`, `jpeg`, `png`, `gif`, `bmp`): nobody, the conversion library in this host, or the conversion API. See [Image conversion](../README.md#image-conversion). |
| `IMAGE_FORMATS` | `avif,webp` | `avif` and/or `webp` | The variants made of each raster entry. |
| `IMAGE_CONVERSION_TIMEOUT` | `55` | `1` to `600` | Seconds one conversion may take. For `remote`, keep it above the API's own conversion time limit, so that the API reports its timeout first. |
| `IMAGE_API_URL` | not set | `http://` or `https://` base URL, without credentials, query or fragment | `remote`: the base URL of the conversion API. Required with `remote`; `check-env` warns about plain `http://`. |
| `IMAGE_API_KEY` | not set | 16 or more printable ASCII characters | `remote`: the API key, sent as `Authorization: Bearer`. Required with `remote`. |
| `IMAGE_API_CA_FILE` | not set | absolute path of a readable PEM file | `remote`: the authorities that sign the API certificate; the system bundle otherwise. The certificate is always verified. |
| `IMAGE_API_FALLBACK` | `false` | boolean | `remote`: converts locally while the API cannot convert (unreachable, breaker open, no capacity). Ignored, with a note, for other converters. |
| `RESIZE_WIDTHS` | not set | 1 to 16 distinct widths, `1` to `16383` | The widths the signed resize URLs may ask for. Requires `RESIZE_KEY` and a converter. Not set: resize URLs are not served. |
| `RESIZE_KEY` | not set | 32 or more printable ASCII characters without spaces | The HMAC-SHA256 key of the resize URLs. |

## Logging and metrics

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `DEBUG` | `false` | boolean | Turns on `display_errors`, `E_ALL` and the `debug` log level. Keep it `false` in production: PHP warnings would become part of asset bodies. `check-env` warns while it is on. |
| `LOG_FILE` | `logs/proxy.log` | absolute path, `stderr` or `error_log` | Where the JSON log lines go. `stderr` suits containers (PHP-FPM with `catch_workers_output`); `error_log` uses PHP's `error_log()`. |
| `LOG_LEVEL` | `notice` (`debug` with `DEBUG=true`) | `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` | The least severe level written. |
| `METRICS_KEYS` | not set | keys of 16 or more printable ASCII characters without spaces or commas, or `sha256:` and the 64 hex digits of a key | The keys a scraper sends as `Authorization: Bearer <key>` on `GET /metrics`. Not set: `/metrics` is not served. They are separate from the purge keys. |
| `METRICS_STORE` | `file` | `file` or `off` | `file` keeps the counters in `var/metrics/`, shared by every PHP process of the host. `off` counts nothing and saves its cost: see [Metrics store cost](performance.md#metrics-store-cost). |

## Miss rate limit and Early Hints

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `MISS_RATE_LIMIT` | `0` (off) | `0` to `1000000` | Origin fetches per minute that one client may cause; above it, `429` with `Retry-After`. Hits are never limited. `check-env` notes when it is on and `TRUSTED_PROXIES` is empty. See [Miss rate limit](operations.md#miss-rate-limit). |
| `MISS_RATE_LIMIT_BURST` | `60` | `1` to `100000` | The fetches one client may cause at once after a pause. |
| `EARLY_HINTS` | not set | a `Link` header value | Sent as a `103 Early Hints` response before a miss is fetched, where PHP can send one (FrankenPHP). PHP-FPM and mod_php cannot, and send nothing. See [Early Hints](operations.md#early-hints). |

## Cluster and eviction

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `CLUSTER_PEERS` | not set | `https://` base URLs, without user, path, query or fragment | The other instances, which receive every purge this instance accepts. Requires `PURGE_KEYS`. See [Running several instances](../README.md#running-several-instances). |
| `CLUSTER_CA_FILE` | system CAs | absolute path | The CA bundle that verifies the certificates of the peers. |
| `CLUSTER_TIMEOUT` | `2` | `1` to `30` | Seconds a peer has to confirm a purge. |
| `CACHE_MAX_SIZE` | `0` (no cap) | bytes, or a number with `K`, `M`, `G` or `T` (powers of 1024) | The size `cache:evict` brings `public/_cache` down to. |

## PHP runtime

These keys are applied by the standalone front controller only; an application that embeds the kernel keeps its own settings.

| Key | Default | Accepted values | Meaning |
| --- | --- | --- | --- |
| `CHARSET` | `UTF-8` | string | PHP `default_charset`. |
| `TIMEZONE` | `UTC` | a time zone identifier, in any letter case | `date_default_timezone_set()`. An unknown identifier is a configuration error. |
| `LOCALE` | `en_US` | string | `setlocale()` for `LC_TIME`, and with `CHARSET` for `LC_MONETARY`. |

## Keys removed in 3.0

The 2.x conversion keys are no longer read. When one of them is still set, in the environment or in `.env`, `check-env` prints a warning with the advice of `src/Config/DeprecatedKeys.php`:

| Removed key | Replacement | How to move the value |
| --- | --- | --- |
| `IMAGE_CONVERSION_MODE` | `IMAGE_CONVERTER` | `native` becomes `local`, `api` becomes `remote`, `none` stays `none`. |
| `WEBP_API_SERVER` | `IMAGE_API_URL` | The base URL of the conversion API v2, with `IMAGE_CONVERTER=remote`, and `IMAGE_API_CA_FILE` for a private CA. |
| `WEBP_API_KEY` | `IMAGE_API_KEY` | A key of 16 bytes or more of the conversion API v2, sent as `Authorization: Bearer`. |

See [UPGRADING.md](../UPGRADING.md) for the other changes from 2.x.
