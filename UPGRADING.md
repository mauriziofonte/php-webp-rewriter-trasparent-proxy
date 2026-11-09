# Upgrading from 2.x to 3.0

3.0 is a rewrite of the proxy. The cache layout, the configuration, the web server configuration, the purge mechanism and several responses changed. Read this page before you upgrade, and keep the 2.x installation until the new one serves traffic.

- [Upgrade steps](#upgrade-steps)
- [Requirements](#requirements)
- [Configuration](#configuration)
- [Web server configuration](#web-server-configuration)
- [Cache layout](#cache-layout)
- [Move or delete the 2.x cache](#move-or-delete-the-2x-cache)
- [Purge](#purge)
- [Responses](#responses)
- [Image conversion](#image-conversion)
- [Origin requests](#origin-requests)
- [Files removed from the project](#files-removed-from-the-project)
- [Other applications in the same installation](#other-applications-in-the-same-installation)

## Upgrade steps

1. Check out 3.0 and run `composer install --no-dev --classmap-authoritative` for the PHP version that runs the proxy (7.2.5 or later).
2. Update `.env` (see [Configuration](#configuration)): add `PROXY_HOSTS`, move the conversion keys, review `UPSTREAM_VERIFY_TLS`.
3. Run `php bin/proxy-cdn check-env` until it reports no error, and read its warnings: it names every deprecated key that is still set and what 2.x left in the installation.
4. Replace the web server configuration with the one of 3.0 (see [Web server configuration](#web-server-configuration)).
5. Run `php bin/proxy-cdn cache:migrate-2x`, review the plan, then run it again with `--force` (or `--wipe --force`).
6. Replace every `?purge=1` and `?purge_all=1` with an authenticated `PURGE` (see [Purge](#purge)).
7. Delete `.cached.env.php` from the project root, if `cache:migrate-2x` has not already done so.

## Requirements

- **PHP 7.2.5 or later** (up to 8.5), because of the minimum versions of the Symfony components.
- **New Composer dependencies**: `symfony/console`, `psr/log` and `mfonte/secure-image-converter`, which Composer resolves from its GitHub repository (declared in `composer.json`). Run `composer install` again.
- **A missing `vendor/`** is no longer answered with a plain-text message. `public/index.php` requires the Composer autoloader, so without it every request ends in a PHP fatal error, which PHP-FPM reports as `500`.
- **ext-curl** is still what `check-env` requires, and its absence is reported as an error there. At runtime, 2.x answered every request with a plain-text message without it; 3.0 fetches from the origin with PHP streams instead (ext-openssl for https origins), while the conversion API client and the parallel warm-up and purge fan-out need ext-curl.

## Configuration

**Environment first.** Every key is read from the PHP environment first and from `.env` second, and `.env` is optional when the environment carries the whole configuration. 2.x stopped with a message when `.env` was missing.

**Validation.** Every value is validated before a request is answered. An invalid configuration answers every request with `503` and the error code `ENV_FILE_LOAD_ERR` (2.x answered `404`). Some values that 2.x accepted are now errors, for example:

- `TIMEZONE` must be a valid time zone identifier. 2.x passed any value to `date_default_timezone_set()`, which fell back to UTC with a warning; an unknown identifier is now a configuration error.
- `DEBUG=TRUE` (or any other case of `true`) now turns debug on; 2.x matched `true` and `1` exactly and left it off. Check `DEBUG` before you upgrade.
- Integers must be in range (`UPSTREAM_TIMEOUT` from 1 to 300, for example), booleans must be `true`, `false`, `1` or `0` in any case, and `SOURCE_ORIGIN` must be an `http://` or `https://` URL.

**The compiled configuration** is `.config.cache.php` (mode `0600`), recompiled automatically when `.env` or the environment changes. The 2.x file `.cached.env.php` is never read: delete it, since it holds the 2.x configuration in clear.

**New required key.** `PROXY_HOSTS` lists the host names the proxy answers for. Any other `Host` header now gets `421 Unknown host`.

```dotenv
PROXY_HOSTS = "cdn.example.com"
```

**Removed keys.** The 2.x conversion keys are no longer read. `check-env` warns while one is still set, with this advice (from `src/Config/DeprecatedKeys.php`):

| 2.x key | 3.0 key | How to move the value |
| --- | --- | --- |
| `IMAGE_CONVERSION_MODE` | `IMAGE_CONVERTER` | `native` becomes `local` (a backend of the conversion library in this host, no `cwebp` required), `api` becomes `remote` (the conversion API v2), `none` stays `none`. |
| `WEBP_API_SERVER` | `IMAGE_API_URL` | The base URL of the conversion API v2, with `IMAGE_CONVERTER="remote"`, and `IMAGE_API_CA_FILE` for a private CA. |
| `WEBP_API_KEY` | `IMAGE_API_KEY` | A key of 16 bytes or more of the conversion API v2, sent as `Authorization: Bearer`. |

The other 2.x keys keep their names: `DEBUG`, `SOURCE_ORIGIN`, `SOURCE_ORIGIN_SERVER`, `CACHEABLE_EXTENSIONS`, `CACHE_EXPIRY`, `NEGATIVE_CACHE_TTL`, `NETWORK_ERROR_TTL`, `UPSTREAM_TIMEOUT`, `UPSTREAM_CONNECT_TIMEOUT`, `UPSTREAM_VERIFY_TLS`, `UPSTREAM_FOLLOW_REDIRECTS`, `CHARSET`, `TIMEZONE` and `LOCALE`.

**Changed defaults and meanings.**

| Key | 2.x | 3.0 |
| --- | --- | --- |
| `UPSTREAM_VERIFY_TLS` | `false` | `true`. An https origin with a self-signed or mismatched certificate now answers `503`: fix the certificate, or set `UPSTREAM_VERIFY_TLS=false` on a trusted network only. `check-env` prints a note while the key is unset. |
| `NETWORK_ERROR_TTL` | the lifetime of a `.netfail` marker per file | how long the circuit breaker of the whole origin stays open, after `CIRCUIT_BREAKER_THRESHOLD` consecutive failures |
| query strings | every distinct query string was an entry and an origin fetch | only the parameters of `QUERY_STRING_ALLOWLIST` (`v,ver,version,id`) count; `*` restores the 2.x behavior, with a warning |

The new keys are listed in [docs/configuration.md](docs/configuration.md).

## Web server configuration

Replace the 2.x `public/.htaccess` with the one of 3.0, and include it in the virtual host as [`config/apache/proxy-cdn.conf`](config/apache/proxy-cdn.conf) does, in both the `:443` and the `:80` virtual hosts. nginx is now supported with [`config/nginx/`](config/nginx/). Both serve every hit without PHP, including the entries of canonical query strings, the variants and the encodings.

**HTTPS redirect.** 2.x believed `X-Forwarded-Proto: https` from any client. 3.0 believes it only from the addresses you list: `PROXYCDN_HTTPS` in both Apache virtual hosts, the `geo` block in nginx, and `TRUSTED_PROXIES` for PHP. Behind a TLS-terminating load balancer, list it, or the redirect loops (see [Trusted proxies](docs/deployment.md#trusted-proxies-and-the-https-redirect)). Only the hosts of `PROXY_HOSTS` are redirected, and the web server must list them too: `PROXYCDN_HOST` in the Apache `:80` virtual host, the `$proxycdn_known_host` map in nginx. 2.x redirected any `Host`.

**New deny rules.** Both configurations now refuse:

- `/_cache/...`, the cache read directly, which would bypass the host allowlist;
- `/_qs/...`, the 2.x query-string buckets;
- any path segment that starts with a dot;
- the compiled configuration `.config.cache.php` and the 2.x `.cached.env.php`, next to the files 2.x already denied.

**Rules removed.** The 2.x rules that refused `/vendor/`, `/helpers/`, `/scripts/` and `/logs/` are gone. The document root is `public/`, where none of these directories exists, and an origin may well serve `/vendor/livewire/livewire.js`: such paths are proxied like any other.

**Headers.** Every hit carries the headers PHP sends (see [Response headers](docs/deployment.md#response-headers)). `X-Powered-By` no longer includes a version, and `X-Cache-Status: HIT` is sent on cache hits only, not on every response. The `max-age` of the hits is in `public/.htaccess` and `config/nginx/proxy-cdn-headers.conf`: keep it equal to `CACHE_EXPIRY`.

## Cache layout

| 2.x | 3.0 |
| --- | --- |
| `public/<path>` | `public/_cache/<host>/<path>` |
| `public/<path>.webp`, next to the raster | `public/_cache/<host>/.webp/<path>.webp` (and `.avif/<path>.avif`) |
| `public/_qs/<hash>/<path>` | `public/_cache/<host>/.q/<query>/<path>`, or `.qh/<hash>/<path>` |
| `<path>.404` and `<path>.netfail` markers next to the files | `var/negative/` (at most 4096 files) and one circuit breaker per origin in `var/breaker/` |
| | `public/_cache/<host>/.m/`: entries the origin wants revalidated sooner than `CACHE_REVALIDATE_BELOW` |
| | `public/_cache/<host>/.pending/`: new images waiting for their variants |

3.0 neither reads nor serves the 2.x locations. The proxy state now lives in `var/` of the project, which the PHP user must be able to write; [docs/caching.md](docs/caching.md) describes every directory.

## Move or delete the 2.x cache

`bin/proxy-cdn cache:migrate-2x` imports the 2.x cache into the 3.0 layout:

```bash
php bin/proxy-cdn cache:migrate-2x                 # print the plan; nothing changes
php bin/proxy-cdn cache:migrate-2x --force         # apply it
php bin/proxy-cdn cache:migrate-2x --wipe --force  # delete the 2.x cache instead
```

- Each entry moves to `public/_cache/<host>/<path>`, for the host of `SOURCE_ORIGIN` in `PROXY_HOSTS`. When several hosts use that origin, name one with `--host`.
- An `X.webp` next to exactly one moved raster becomes its WEBP variant; any other `X.webp` is deleted, and 3.0 converts again.
- When `IMAGE_CONVERTER` makes variants, a raster entry moves to the pending tier instead, with its `X.webp` staged as its WEBP variant when WEBP is enabled. The first request PHP answers for it makes its other variants and publishes it.
- An entry that 3.0 already holds, published or pending, is deleted from `public/`, never moved over it. Nothing in `public/_cache` is changed.
- `public/_qs/` is deleted: its hashed names cannot be mapped back to URLs. The `.404` and `.netfail` markers of entries and `.cached.env.php` are deleted too.
- Files that are not cache entries stay where they are: `robots.txt`, `.well-known/`, files whose extension is not cacheable, paths the proxy refuses. Symbolic links are never followed or moved.

`--wipe` deletes the same files instead of moving them; the cache then fills again from the origin.

## Purge

The anonymous `?purge=1` and `?purge_all=1` are gone: anyone could empty the cache and send the load to the origin. Such a request now gets a `401` whose body explains the new mechanism, and purges nothing. A purge is now a `PURGE` request authenticated with a key of `PURGE_KEYS`, with a Bearer secret or an HMAC (see [Purge](README.md#purge)). `bin/proxy-cdn cache:purge <url>...` purges from the command line.

- A purge of a URL without a query string now also removes the entries of every query string of that path; 2.x left them.
- `purge_all` also removed the files of other URLs that shared the base name (`hero.png` for `hero.jpg`). Purge those by their own URL.

## Responses

| Situation | 2.x | 3.0 |
| --- | --- | --- |
| A raster image, to a client that accepts WEBP | `301` to `X.webp` | the variant at the same URL, with `Vary: Accept`; AVIF preferred when accepted |
| A request for `X.webp` that 2.x redirected to | the WEBP copy | the WEBP variant of the raster, as long as `LEGACY_WEBP_URLS=true` (the default) |
| An invalid configuration | `404` | `503` `ENV_FILE_LOAD_ERR` |
| The proxy cannot write its cache | `404` | `500` |
| The origin answers `403` | `503`, and the breaker opened | `403` |
| The origin answers a `3xx` | `503`, and the breaker opened | the redirect, rewritten to the proxy path when it stays below the origin; `502` otherwise |
| Another `4xx` of the origin, or a body over `UPSTREAM_MAX_SIZE` | `503` | `502` |
| A path that names another file (`..`, encoded `/`, dot segments) | `404` for `/..`, others accepted | `400` |
| A method other than `GET`, `HEAD`, `OPTIONS` or `PURGE` | served like `GET` | `405` |
| `OPTIONS` | `Access-Control-Allow-Credentials: true` with `*`, and `POST` allowed | a valid preflight for anonymous requests: `GET, HEAD, OPTIONS`, no credentials |
| A host outside `PROXY_HOSTS` | served | `421` |
| `X-Cache-Status` | `HIT` on every Apache response | `HIT`, `MISS`, `REVALIDATED`, `STALE` or `BYPASS` on assets, absent on errors |
| `Content-Type` | from the origin or the 2.x table | from the extension, with registered types (`text/javascript`, `font/woff2`, `image/vnd.microsoft.icon`) |
| An origin response with `no-store` or `private` | stored | streamed without storing (`BYPASS`) |
| A stylesheet | root-relative `url()` references rewritten with the scheme and host of the request | stored and served as the origin sent it; a root-relative reference already resolves against the proxy |
| A conversion that fails | `500` | the original, and the failure is logged |

The error bodies keep the 2.x format ("Error code" and "Ray ID"); the Ray ID is now random for errors without a path.

## Image conversion

- 2.x converted to WEBP only, and on query-string hits it converted, and failed, again on every request. 3.0 makes AVIF and WEBP variants (`IMAGE_FORMATS`) after the response, verifies every output, and remembers failures.
- The conversion API client of 3.0 speaks the conversion API v2 (`POST /v2/convert`, `Authorization: Bearer`), always verifies its certificate and never follows redirects. The 2.x client, with its `-x-api-key` header and no TLS verification, is gone.
- `IMAGE_API_FALLBACK=true` converts locally while the API is unavailable.

## Origin requests

- The origin certificate is verified by default (see `UPSTREAM_VERIFY_TLS` above).
- The `User-Agent` of origin requests is `mfonte-proxy-cdn/3 (+https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy)`, or `UPSTREAM_USER_AGENT`.
- The origin receives the allowlisted query parameters as the client wrote them; 2.x forwarded a canonical form, which turned `a.b=1` into `a_b=1`.
- A path is decoded once: `/my%20file.png` is stored as `my file.png`, where the web server finds it. 2.x stored it as `my%20file.png`, which the web server never found.
- The responses of the origin are read as a shared cache reads them (RFC 9111): short-lived entries go to the managed tier and are revalidated (see [Cache tiers](docs/caching.md#cache-tiers)). `CACHE_REVALIDATE_BELOW=0` keeps every entry until it is purged, as 2.x did.

## Files removed from the project

`bootstrap.php`, `helpers/functions.php` and `scripts/check-env.php` are gone. The front controller is still `public/index.php`; `composer check-env` now runs `bin/proxy-cdn check-env`, and the code lives in `src/` under the `Mfonte\ProxyCdn` namespace.

## Other applications in the same installation

`cache:migrate-2x` tells a 2.x entry by its name only: every file of `public/` with a cacheable extension and a path the proxy accepts is taken as 2.x cache, even in the directory of another application (`public/other-app/app.js`). Review the dry run, which lists every path it would move, delete or leave, before you run it with `--force`.

3.0 can also be mounted inside another PHP application instead of running as its own virtual host, as the CI does in a Laravel application: see [Embedding the kernel](docs/deployment.md#embedding-the-kernel-in-another-application).
