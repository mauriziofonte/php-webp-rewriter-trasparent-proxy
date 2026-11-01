# PHP Transparent Proxy CDN (with optional on-the-fly WEBP conversion)

A self-contained PHP application that turns a VirtualHost into a **self-hosted CDN** for the static assets of another site.

It acts as a **transparent proxy** between the origin server and the client's browser: on the first request a missing asset is fetched from the origin and cached on the local filesystem; on every subsequent request the web server (Apache or nginx) serves the cached copy directly, without going through PHP. If the asset is a raster image (`jpg`, `jpeg`, `png`, `gif`, `bmp`) the proxy optionally generates a `.webp` variant alongside it, which the web server serves at the same URL to the clients that accept it (see *Content negotiation* below).

> Historical note: the repository name (`php-webp-rewriter-trasparent-proxy`) contains a typo kept for backward-compatibility. The project is a transparent proxy, not a "trasparent" one.

## Scope and honest positioning

This is a **small, hobbyist-to-small-production** project. It is appropriate for a narrow set of scenarios and has deliberate limits. Read this section before adopting it so you do not mistake it for something it is not.

**Use this project when:**

- You are on a **LAMP / LEMP** VPS, no ops team, no budget for a third-party CDN, and want WEBP conversion plus a filesystem cache in front of an origin you do not want to modify.
- Traffic is **moderate and predictable** (roughly: a personal site, a portfolio, a small business site, internal tools). Order of magnitude: up to a few dozen requests per second, not hundreds.
- **Simplicity wins over performance**: you want one `git clone`, one `composer install`, one `.env`, and you accept PHP in the serving path for misses and for the entries the origin wants revalidated.
- You want the deployment to be a single box you fully control (no external DNS dependency, no vendor account, no egress to someone else's network).

**Do NOT use this project when:**

- You need **production-grade CDN semantics**: Cloudflare's free tier (Polish for WEBP, cache, WAF, DDoS protection, anycast) does all of this, properly, for zero euros and five minutes of DNS work. Use that instead.
- You expect **hundreds of requests per second or more**, or traffic spikes from bots/scanners. PHP-FPM worker pools cap concurrency well below what `nginx proxy_cache` or Varnish deliver with a tenth of the memory.
- You need **horizontal scaling** behind a load balancer. The on-disk cache is per-instance; purges and warm-up do not propagate. Building shared state on top of this project is more work than starting from nginx/Varnish.
- You need **features this project deliberately does not implement** yet: size-capped or LRU cache eviction, metrics/observability endpoints, HTTP/2 push, HTTP/3. (Range and conditional requests, Brotli and gzip negotiation, revalidation with the origin and request coalescing are covered: see *Origin cache semantics*.)
- You need an **audited security posture**. This repository has no automated test suite and has not undergone a formal security review. The codebase has been manually hardened (atomic writes, circuit breaker, query-string caching, whitelist header mirroring, structured logging) but manual hardening is not the same as a track record in the wild.

**Real value-add of this project over the alternatives:**

The one thing this project does that is genuinely cumbersome to replicate with off-the-shelf tools is **on-the-fly WEBP conversion against an unmodifiable origin, negotiated per client at the same URL, served from an Apache or nginx + PHP stack without a cache daemon**. If that specific combination matches your constraints, this is a reasonable starting point. Everything else (caching, HTTPS redirect, compression, ETags) is solved better elsewhere.

## Table of contents

- [Scope and honest positioning](#scope-and-honest-positioning)
- [Request lifecycle](#request-lifecycle)
- [Installation](#installation)
- [Configuration](#configuration)
- [Web server configuration](#web-server-configuration)
- [Image conversion modes](#image-conversion-modes)
- [Cache purge](#cache-purge)
- [Origin cache semantics](#origin-cache-semantics)
- [Query string caching](#query-string-caching)
- [Negative caching and circuit breaker](#negative-caching-and-circuit-breaker)
- [Content negotiation (Accept: image/avif, image/webp)](#content-negotiation-accept-imageavif-imagewebp)
- [Logging](#logging)
- [Cache maintenance](#cache-maintenance)
- [Rewriting origin references to the CDN](#rewriting-origin-references-to-the-cdn)
- [Security considerations](#security-considerations)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Request lifecycle

1. The client requests a web page of `https://origin.example.com`.
2. That page references a static asset as `https://cdn.example.com/images/whatever/resource.jpg` (instead of a relative path on the origin).
3. The CDN receives the request. The web server then **decides who handles it**:
    - A `GET` or `HEAD` whose cache file **exists on disk** (`public/_cache/<host>/<path>`, or the directory of its query string, see *Query string caching*): the web server serves it, as its AVIF or WEBP variant or its Brotli or gzip encoding when the client accepts one. PHP is not invoked.
    - Anything else (a miss, a query string the web server cannot map, `PURGE`, `OPTIONS`): the request reaches `index.php`.
4. `index.php` runs [public/index.php](public/index.php). Its flow is:
    1. Refuse a host outside `PROXY_HOSTS` (`421`), then validate the request (traversal, extension whitelist).
    2. Compute the cache location: `public/_cache/<host>/<path>`, or a directory of the query string below it.
    3. Answer an authenticated `PURGE` (see *Cache purge*); refuse the anonymous `?purge=` of 2.x with a 401.
    4. Short-circuit to 404 or 410 if the negative cache still remembers the entry.
    5. Serve from disk if the cache file exists, negotiated as the web server does.
    6. Answer 503 without a fetch while the circuit breaker of the origin is open.
    7. Otherwise, fetch the origin over HTTPS with cURL, distinguish a real 404 from a transient network failure, persist the bytes atomically, optionally convert to webp, and stream the response.

## Installation

1. Clone this repo onto a LAMP/LEMP server and point a VirtualHost's `DocumentRoot` (nginx: `root`) at `./public`.
2. Configure the web server (see *Web server configuration*). Apache: `sudo a2enmod rewrite headers setenvif deflate mime`.
3. Make sure `curl` is available in your PHP build (`php -m | grep -i curl`).
4. Install dependencies with `composer install`. The `post-install` hook runs `bin/proxy-cdn check-env --informational`, which validates every configuration key (including URL format, numeric ranges and suspicious extensions); `composer check-env` runs the same check and fails on any error.
5. Copy `.env.example` to `.env` and adjust it. The defaults are safe for production (`DEBUG=false`, and the origin TLS certificate is verified; an origin with a self-signed certificate on a trusted network needs `UPSTREAM_VERIFY_TLS=false`).
6. If `IMAGE_CONVERSION_MODE = "native"`, install the `cwebp` binary (see *Image conversion modes*).
7. If `IMAGE_CONVERSION_MODE = "api"`, deploy [mauriziofonte/php-image-to-webp-conversion-api](https://github.com/mauriziofonte/php-image-to-webp-conversion-api) on a separate VirtualHost and set `WEBP_API_SERVER` / `WEBP_API_KEY`.

### Minimum PHP version

PHP **7.2.5** or later (required by `symfony/http-foundation ^5.4`). The application source targets PHP 7.x syntax only; `match`, arrow functions and PHP-8-only string helpers are not used.

### The compiled configuration

The first request after a fresh install compiles the configuration into a small PHP file named `.config.cache.php` in the project root, written atomically (tmp + rename), so that `.env` is not parsed and validated on every request. The file is recompiled automatically on the next request whenever `.env` or a configuration variable of the environment changes; there is nothing to delete by hand. If the project root is not writable, the configuration is simply read on every request.

## Configuration

Every setting is read from the environment first and from `.env` second: a variable set (and not empty) in the environment of PHP wins over the same key in `.env`, and `.env` is optional when the environment carries the whole configuration. An empty value or the literal `null` means "not set". Defaults come from [.env.example](.env.example). The values are validated when the proxy starts, with the same rules as `composer check-env`; an invalid configuration answers every request with a 503 `ENV_FILE_LOAD_ERR` and is logged to `logs/proxy.log`.

| Key                               | Type      | Default                   | Description                                                                                                                                                     |
|-----------------------------------|-----------|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `DEBUG`                           | bool      | `false`                   | When `true`, enables `display_errors`, `E_ALL` and info-level log entries. **Must be `false` in production** or PHP warnings will poison streamed asset bodies. |
| `SOURCE_ORIGIN`                   | URL       | *required*                | Origin server from which missing assets are fetched. Validated at install time.                                                                                 |
| `SOURCE_ORIGIN_SERVER`            | `ip:port` | *optional*                | Pins the cURL connection to a specific `host:port`, bypassing DNS (and therefore any CDN/WAF in front of the origin). See security notes. Not with several origins in `PROXY_HOSTS`. |
| `PROXY_HOSTS`                     | CSV       | *required*                | The host names the proxy answers for: `cdn.example.com` fetches from `SOURCE_ORIGIN`, `cdn.example.com=https://origin.example.com` from its own origin. Each host has a cache directory of its own, `public/_cache/<host>/`; any other `Host` gets `421 Unknown host`. A name matches itself only, in any letter case, without wildcards. |
| `SENDFILE`                        | enum      | `none`                    | How PHP sends a cache file: `none` (PHP streams it), `x-sendfile` (Apache with mod_xsendfile) or `x-accel-redirect` (nginx).                                    |
| `PRECOMPRESS`                     | bool      | `true`                    | Stores a gzip (ext-zlib) and a Brotli (ext-brotli) encoding of text, SVG, icon and font entries next to them, served to the clients that accept them.           |
| `LEGACY_WEBP_URLS`                | bool      | `true`                    | Answers an `X.webp` URL, where the 301 of 2.x sent clients, with the WEBP variant of the raster `X.jpg`, `X.png`, ... (cached, or fetched when the origin has no `X.webp`), unless an `X.webp` entry is cached. |
| `CACHEABLE_EXTENSIONS`            | CSV       | `css,js,...,ico`          | Whitelist of file extensions the proxy will serve. Any extension outside this list is rejected with a 404.                                                      |
| `CACHE_REVALIDATE_BELOW`          | seconds   | `3600`                    | An entry whose origin lifetime (`s-maxage`, `max-age`, `Expires`) is shorter is kept where only PHP serves it, and revalidated with the origin once expired (see *Origin cache semantics*); `0` keeps every entry until purged, as 2.x did. |
| `STALE_WHILE_REVALIDATE`          | seconds   | `60`                      | How long such an expired entry is still served while PHP revalidates it after the response, when the origin sends no `stale-while-revalidate`.                 |
| `CACHE_EXPIRY`                    | seconds   | `86400` (1d); `.env.example` sets `31536000` | `max-age` sent on the responses of PHP. The web server configurations (`public/.htaccess`, `config/nginx/proxy-cdn-headers.conf`) send `31536000` on the hits they serve: change them with it; `composer check-env` warns when they differ. Combined with `must-revalidate` (not `immutable`), so a purge is effective. |
| `NEGATIVE_CACHE_TTL`              | seconds   | `300`                     | How long a 404 marker is honored before the proxy re-queries the origin.                                                                                        |
| `NETWORK_ERROR_TTL`               | seconds   | `30`                      | How long the circuit breaker of the origin stays open once it opens (see *Negative caching and circuit breaker*); `0` disables the breaker.                     |
| `CIRCUIT_BREAKER_THRESHOLD`       | int       | `5`                       | Consecutive unavailability failures of the origin (5xx, 429, timeout, DNS, connection, TLS) that open its circuit breaker; 1 to 1000.                          |
| `UPSTREAM_TIMEOUT`                | seconds   | `30`                      | Hard cap on a single upstream fetch. Combined with `CURLOPT_LOW_SPEED_LIMIT=1024` + `_TIME=10`, this also aborts stalled transfers. A stalled DNS lookup is bounded by the system resolver instead. |
| `UPSTREAM_CONNECT_TIMEOUT`        | seconds   | `5`                       | Socket-level connect timeout.                                                                                                                                   |
| `UPSTREAM_VERIFY_TLS`             | bool      | `true`                    | Verifies the origin TLS certificate and host name. 2.x defaulted to `false`; `check-env` notes the change when the key is unset and the origin is https.        |
| `UPSTREAM_FOLLOW_REDIRECTS`       | bool      | `false`                   | If `true`, the proxy follows origin redirects. A redirect to another origin is followed only when every address of its host is public (no loopback, private, link-local or reserved range, checked after DNS), and the connection is pinned to the checked address. |
| `QUERY_STRING_ALLOWLIST`          | CSV       | `v,ver,version,id`        | Query parameters that key the cache and reach the origin, by decoded name and letter case; `*` keeps every parameter (as 2.x did), `none` keeps none.          |
| `QUERY_STRING_MODE`               | enum      | `ignore`                  | `ignore` drops the other parameters from the cache key and the origin request; `reject` answers 400 `Query parameter not allowed`.                              |
| `PURGE_KEYS`                      | CSV       | *empty*                   | `scope=secret` pairs that authenticate a `PURGE` (see *Cache purge*); the scope is the origin host name or `*`. Empty disables purging.                          |
| `TRUSTED_PROXIES`                 | CSV       | *empty*                   | IPs and CIDR blocks (IPv4, IPv6) of the reverse proxies whose `X-Forwarded-Proto` and `X-Forwarded-For` are believed; same key and syntax as the conversion API. Empty trusts nobody. |
| `IMAGE_CONVERSION_MODE`           | enum      | `none`                    | One of `native`, `api`, `none`.                                                                                                                                 |
| `WEBP_API_SERVER`                 | URL       | `null`                    | Only used when mode = `api`.                                                                                                                                    |
| `WEBP_API_KEY`                    | string    | `null`                    | Only used when mode = `api`.                                                                                                                                    |
| `CHARSET` / `TIMEZONE` / `LOCALE` | string    | `UTF-8` / `UTC` / `en_US` | Standard PHP locale setup.                                                                                                                                      |

## Web server configuration

The web server serves the hits; the official configurations are [public/.htaccess](public/.htaccess) (Apache, with the example virtual host [config/apache/proxy-cdn.conf](config/apache/proxy-cdn.conf)) and [config/nginx/](config/nginx/) (nginx: copy it to `/etc/nginx/proxy-cdn/` and start from [proxy-cdn.conf](config/nginx/proxy-cdn.conf)). Both send a hit with the headers PHP sends: `Cache-Control`, `X-Cache-Status: HIT`, `Access-Control-Allow-Origin: *`, `Cross-Origin-Resource-Policy: cross-origin`, `Timing-Allow-Origin: *`, `X-Content-Type-Options: nosniff`, the SVG policy, `Vary` and `Content-Encoding`; and both hand `PURGE`, `OPTIONS`, misses and unlisted hosts to PHP. `X-Cache-Status` is `HIT` on a file served from the cache, `MISS` on a file fetched from the origin, and absent on an error.

**HTTPS redirect behind a proxy.** Plain-HTTP requests are redirected to https. Behind a TLS-terminating reverse proxy, the web server must believe the `X-Forwarded-Proto` of that proxy only, as PHP does with `TRUSTED_PROXIES`; 2.x believed any client. List the same addresses in the web server:

- Apache, in the virtual host: `SetEnvIfExpr "-R '10.0.0.0/8' && %{HTTP:X-Forwarded-Proto} == 'https'" PROXYCDN_HTTPS=on`.
- nginx, in the `geo $proxycdn_trusted_proxy` block of `proxy-cdn.conf`: `10.0.0.0/8 1;`.

Without them `X-Forwarded-Proto` is ignored, which is the safe default: a TLS-terminating proxy then gets redirect loops until it is listed.

**Sending files.** With `SENDFILE=x-accel-redirect` (nginx) or `SENDFILE=x-sendfile` (Apache with mod_xsendfile, `XSendFilePath` set to `public/_cache`), the files PHP chooses are sent by the web server too. PHP hands a file over only when the web server sets the variable `PROXYCDN_SENDFILE` to the same mode, as `config/nginx/proxy-cdn-fastcgi.conf` does, and the `mod_xsendfile` block of `config/apache/proxy-cdn.conf`; otherwise it streams the file itself, so that a missing module never yields empty responses.

**Upgrading from 2.x.** 2.x stored entries at `public/<path>` and `public/_qs/`; 3.0 neither reads nor serves them. Delete them once the new layout is in place (everything in `public/` but `index.php`, `.htaccess` and `_cache/`). The `X.webp` URLs that 2.x redirected clients to keep working (`LEGACY_WEBP_URLS`).

## Image conversion modes

`IMAGE_CONVERSION_MODE` controls how a newly-cached raster image is handled:

- **`native`** - the image is converted on the fly using the `cwebp` binary. Requires PHP's `exec()` and `cwebp` in `PATH`. The output is written to a scratch path and then atomically renamed over the destination, so concurrent workers never observe a truncated `.webp`.
- **`api`** - the image is POSTed to an external microservice ([php-image-to-webp-conversion-api](https://github.com/mauriziofonte/php-image-to-webp-conversion-api)) which returns the webp payload as base64. Written atomically.
- **`none`** - conversion is disabled. The original raster image is cached and served as-is.

### Installing `cwebp`

| Distro                 | Command                          |
|------------------------|----------------------------------|
| Debian / Ubuntu        | `sudo apt install webp`          |
| Alpine                 | `apk add libwebp-tools`          |
| Arch / Manjaro         | `sudo pacman -S libwebp`         |
| Fedora / RHEL / CentOS | `sudo dnf install libwebp-tools` |
| macOS (Homebrew)       | `brew install webp`              |

Smoke-test: `cwebp input.jpg -o output.webp`.

## Cache purge

A purge is a `PURGE` request for the asset URL that proves a purge key of the origin. The keys are set in `PURGE_KEYS`, as `scope=secret` pairs separated by commas: the scope is the host name of the origin (the host of `SOURCE_ORIGIN`) or `*` for every origin, and the secret has at least 16 printable ASCII characters without spaces or commas. With no key, every purge is refused.

``` dotenv
PURGE_KEYS = "origin.example.com=0f6c2e7d9a1b4c58e3f2a7d6b9c0e1f2"
```

A request proves a key in one of two ways:

- **Bearer**: `Authorization: Bearer <secret>`. Use it over HTTPS only: the secret travels with the request.
- **HMAC**: `X-Purge-Timestamp: <unix seconds>` and `X-Purge-Signature: <hex HMAC-SHA256>` of `PURGE\n<host>\n<path and query>\n<timestamp>` under the secret, where `<host>` is the `Host` header in lower case (with its port, when not the default one) and `<path and query>` the request target as sent. The timestamp must be within 300 seconds of the proxy clock. Within that window a captured signature can be replayed; the proxy keeps no nonce on purpose: a purge is idempotent (a replay removes again what the same request removed, and makes the next request fetch from the origin once), a Bearer secret can be replayed for as long as it is valid anyway, and a nonce store shared by every PHP worker would be one more piece of state to secure. Use HTTPS, and rotate a key that may have leaked.

``` bash
# Bearer
curl -X PURGE -H 'Authorization: Bearer 0f6c2e7d9a1b4c58e3f2a7d6b9c0e1f2' \
    'https://static.mycdn.com/images/hero.jpg?v=42'

# HMAC
ts=$(date +%s)
sig=$(printf 'PURGE\nstatic.mycdn.com\n/images/hero.jpg?v=42\n%s' "$ts" |
    openssl dgst -sha256 -hmac '0f6c2e7d9a1b4c58e3f2a7d6b9c0e1f2' -r | cut -d' ' -f1)
curl -X PURGE -H "X-Purge-Timestamp: $ts" -H "X-Purge-Signature: $sig" \
    'https://static.mycdn.com/images/hero.jpg?v=42'
```

A purge removes the entry of that URL with its metadata, its AVIF and WEBP variants and its Brotli and gzip encodings, wherever it is stored (the tier the web server serves and the one PHP revalidates), and what the negative cache remembers for it. A purge of a URL **without** query string also removes the entries of **every query string** of the same path (`/app.css?v=1`, `/app.css?v=2`, ...: 2.x left them); a purge of a URL with a query string removes that entry only. It does not fetch the asset again: the next request does. The answer is `200` with `{"purged":true,"path":"/images/hero.jpg","query":"v=42","removed":2}`; a request without a valid key gets `401` with `WWW-Authenticate: Bearer realm="proxy-cdn purge"`. Every purge is logged at `notice` level with the client address (read through `TRUSTED_PROXIES`); every refusal at `warning` level.

The anonymous `?purge=1` and `?purge_all=1` of 2.x were removed: anyone could empty the cache and send the load to the origin. Such a request now gets a `401` whose body explains the `PURGE` request, and purges nothing. `purge_all` also removed the files of other URLs that share the base name (`hero.png` for `hero.jpg`); purge those by their own URL.

## Origin cache semantics

The proxy is a shared cache, and reads the response headers of the origin as one (RFC 9111):

- **`no-store` and `private`** responses are never stored: they are streamed to the client with `X-Cache-Status: BYPASS` and `Cache-Control: no-store` (or `private, no-cache`), and every request fetches them again.
- **Lifetime.** `s-maxage`, else `max-age`, else `Expires` minus `Date`, minus `Age`; `no-cache` means 0. Without any of them, or with a lifetime of `CACHE_REVALIDATE_BELOW` (1 hour) or more, the entry is stored where the web server serves it, and kept until it is purged, as in 2.x: the long-lived assets of a site never touch PHP again. A shorter lifetime puts the entry in `public/_cache/<host>/.m/`, which only PHP serves, with its metadata (`.m/.meta/<path>.json`), and with the `max-age` it has left.
- **Revalidation.** Once such an entry expires, PHP asks the origin with `If-None-Match` and `If-Modified-Since` (the `ETag` and `Last-Modified` the origin sent): a `304` renews it (`X-Cache-Status: REVALIDATED`), a `200` replaces it, a `404` or `410` removes it. Within its `stale-while-revalidate` (the origin's, else `STALE_WHILE_REVALIDATE`) the expired entry is served at once (`STALE`) and revalidated after the response has been sent. When the origin is unavailable (5xx, timeout, open circuit breaker), the expired entry keeps being served (`STALE`), for at most its `stale-if-error` when the origin sets one. `must-revalidate` and `proxy-revalidate` forbid any stale use.
- **Request coalescing.** Of the requests that miss the same entry at the same time, one fetches it from the origin while the others wait for it (at most `UPSTREAM_TIMEOUT` plus 30 seconds, then `503`), then serve what it stored: a burst of 500 requests on a cold URL costs one origin fetch. When that fetch stores nothing (an error, a 403, 404 or 410, a redirect), the waiters answer with the same error or redirect instead of fetching in turn, and when the origin forbids storing the response, the requests of that URL fetch in parallel; either outcome is remembered for 5 seconds. The lock is a file of `var/locks/`, which must not be writable by other users (the proxy logs a warning and fetches without coalescing otherwise).
- **Streaming.** An origin body goes to a temporary file, then to the cache, in chunks: never into PHP memory. Files PHP serves are streamed too, with `ETag`, `Last-Modified`, `304` to `If-None-Match` and `If-Modified-Since`, and `206` to a single `Range` (with `If-Range`).

`X-Cache-Status` is therefore `HIT`, `MISS`, `REVALIDATED`, `STALE` or `BYPASS` on an asset, and absent on an error.

## Query string caching

Cache-busted URLs (e.g. `/hero.jpg?v=42`) are a common origin idiom. A naive disk cache keyed on path alone would pin the very first version the proxy saw and never re-fetch. To avoid that:

Only the parameters named in `QUERY_STRING_ALLOWLIST` (default `v,ver,version,id`, where `id` is the version hash of Laravel Mix, `mix('/css/app.css')` = `/css/app.css?id=<hash>`) count. Add the parameter your build tool uses (`hash`, `t`, ...) if it is another one, or a new build is served from the stale entry of the old one. The others, such as `utm_source` or a random `?_=<timestamp>`, are left out of the cache key and of the origin request (`QUERY_STRING_MODE=ignore`, the default) or refused with a 400 (`QUERY_STRING_MODE=reject`). In 2.x every distinct query string was a cache entry and an origin fetch of its own, so random query strings filled the disk and bypassed the cache.

- A query string with **no allowlisted parameter** keys on the path: `public/_cache/<host>/<path>`.

- Otherwise the allowlisted parameters, sorted by name (repeated names keep their order, escapes in upper case), form the canonical query string. When it is made of letters, digits and `-._~=&` only (at most 200 characters), the entry lives in a directory named after it, which the web server finds by itself; any other one is hashed with SHA-256, and only PHP finds it:

  ``` text
  /hero.jpg?v=42              -> public/_cache/<host>/.q/v=42/hero.jpg       (web server hit)
  /hero.jpg?ver=2&utm_x=1&v=1 -> public/_cache/<host>/.q/v=1&ver=2/hero.jpg  (PHP hit: not the canonical form)
  /hero.jpg?v=1&ver=2         -> the same file                               (web server hit)
  /hero.jpg?v=a%2Fb           -> public/_cache/<host>/.qh/<sha256_prefix>/hero.jpg (PHP hit)
  ```

  The web server looks up the query string exactly as the client sent it, so a request in canonical form is a hit without PHP; another form of the same parameters reaches PHP, which serves the same entry.

  The origin receives the allowlisted parameters as the client wrote them, in their order (`?ver=2&v=1` above); 2.x forwarded its canonical form, which turned `a.b=1` into `a_b=1` and `v` into `v=`.

Direct HTTP access to `/_cache/...` and to the `_qs/` buckets of 2.x is denied: the layout is an internal implementation detail.

## Negative caching and circuit breaker

The proxy remembers two kinds of failure. A `404` is remembered in `var/negative/` of the project, outside the document root: one flat directory of at most 4096 slot files (`<3 hex digits of the SHA-256 of the entry>.neg`), so a flood of random paths creates no directory and no file below `public/` (in 2.x every missing path created its directories and a `<path>.404` marker). Two entries that share a slot replace each other's marker, which only costs one more origin request.

The circuit breaker belongs to the origin, not to a file (2.x kept a `<path>.netfail` marker per file, so a failing origin was contacted again for every other path). Its state is one small file per origin in `var/breaker/`, updated under a lock so that every PHP worker shares it. After `CIRCUIT_BREAKER_THRESHOLD` consecutive unavailability failures it opens: for `NETWORK_ERROR_TTL` seconds every request that would need a fetch is answered `503` with `Retry-After`, while the entries already on disk are still served. Then one request is let through as a probe: an HTTP status of the origin that is not unavailability (a success, but also a `404`, `403`, `410` or redirect) closes the breaker, unavailability opens it again at once, and any other transport failure (a malformed reply, an HTTP/2 stream error) leaves it as it is. A success, or a pause longer than `NETWORK_ERROR_TTL` between failures, starts the count again. The `FilesMatch "\.(404|netfail)$"` rule of [public/.htaccess](public/.htaccess) still hides the markers 2.x left on disk.

| Marker           | Armed when origin returns...                                                                                     | TTL                                  | HTTP status served to the client |
|------------------|------------------------------------------------------------------------------------------------------------------|--------------------------------------|----------------------------------|
| `var/negative/`  | `404 Not Found` or `410 Gone`                                                                                    | `NEGATIVE_CACHE_TTL` (default 300 s) | `404` or `410`                   |
| `var/breaker/`   | `CIRCUIT_BREAKER_THRESHOLD` consecutive `5xx`, `429`, connection refused, DNS failure, TLS error, timeout, or transfer stalled under the low-speed-limit threshold | `NETWORK_ERROR_TTL` (default 30 s), then a probe | `503` + `Retry-After`, for every path of the origin |

The other answers of the origin arm nothing and are answered at once (2.x answered `503` for each and opened the breaker):

| Origin answer                                         | HTTP status served to the client                                          |
|-------------------------------------------------------|---------------------------------------------------------------------------|
| `403`                                                 | `403`                                                                     |
| `3xx` to a URL below `SOURCE_ORIGIN`                  | the same `3xx`, its `Location` rewritten to the path on the proxy         |
| `3xx` anywhere else, or without a usable `Location`   | `502`: the client is never sent to another host, which could be internal |
| another `4xx`, a body over `UPSTREAM_MAX_SIZE`        | `502`                                                                     |

With `UPSTREAM_FOLLOW_REDIRECTS=true` the proxy follows the redirects itself (see the key) and only the last answer counts.

Rationale:

- A real `404` is a persistent signal. 5 minutes of suppression prevents a single missing favicon requested a million times from hammering the origin a million times.
- A network blip is a transient signal. 30 seconds of suppression is a tiny circuit breaker that protects the PHP worker pool (every upstream fetch uses one) without permanently hiding assets that are actually fine.

A successful fetch forgets the negative-cache entry of its file and closes the breaker of the origin. A `PURGE` forgets the negative-cache entry of the purged files.

## Content negotiation (Accept: image/avif, image/webp)

A raster entry (`jpg`, `jpeg`, `png`, `gif`, `bmp`) is always served at its own URL; 2.x answered `301 -> foo.webp` instead, which broke the clients that cached the redirect. The web server and PHP choose the same file from the `Accept` header:

- `image/avif` listed (without `q=0`) and an AVIF variant on disk (`<bucket>/.avif/<path>.avif`): the AVIF variant.
- Otherwise `image/webp` listed and a WEBP variant on disk (`<bucket>/.webp/<path>.webp`): the WEBP variant, which PHP converts on the first such request.
- Otherwise the original raster.

Every raster response carries `Vary: Accept`, so that shared caches keep the variants apart. A bare `*/*` is intentionally NOT treated as support, because old UAs send it and then fail to decode the response.

Text, SVG, icon and font entries get the same treatment for `Accept-Encoding`: the Brotli encoding (`<bucket>/.br/<path>`) to the clients that list `br`, the gzip one (`<bucket>/.gz/<path>`) to those that list `gzip`, with `Vary: Accept-Encoding` (see `PRECOMPRESS`).

## Logging

Errors and relevant events are written to `logs/proxy.log` (created lazily, `0755`, outside the DocumentRoot and also blocked by an `.htaccess` 403 in case the VirtualHost is mispointed).

Log format: one JSON object per line (JSON Lines), with the fields `timestamp` (RFC 3339 with microseconds), `level` (PSR-3 level), `channel` (`proxy-cdn`), `message` and `context`.

Levels:

- **error** - upstream fetch failures (with `reason`, `response_code`, and for a transport failure `transport_error_code` and `transport_error`: the libcurl error number, which the stream transport reports too for the same failure, and the message), conversion failures, an invalid configuration.
- **info** - upstream 404s. Only written when `DEBUG=true`.

An exception in the context is written as an object (`class`, `message`, `code`, `file`, `previous`, and `error_code` when the exception has a `getErrorCode()` method). When the `exception` of an entry comes from the conversion library, its error code is also copied to the context as `conversion_error_code`, the key the conversion API uses for the same codes.

Example entry:

``` text
{"timestamp":"2025-03-14T12:34:56.123456+00:00","level":"error","channel":"proxy-cdn","message":"upstream fetch failed","context":{"url":"https://origin.example.com/hero.jpg","reason":"timeout","transport_error_code":28,"transport_error":"Operation timed out after 30000 ms","response_code":0}}
```

For production, rotate `logs/proxy.log` with a standard tool:

``` bash
# /etc/logrotate.d/php-webp-proxy
/srv/sites/cdn/logs/proxy.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
}
```

## Cache maintenance

The proxy does not auto-clean stale negative markers or old cached files. Run maintenance via cron.

``` bash
# Once, after the upgrade from 2.x: drop the .404 / .netfail markers it
# left next to the cache files. The state of 3.0 lives in var/negative/
# and var/coalescing/ (at most 4096 files each), var/locks/ (a file only
# while its lock is held) and var/breaker/ (one file per origin), and
# needs no cleaning.
find /srv/sites/cdn/public \( -name '*.404' -o -name '*.netfail' \) -type f -delete

# Every week: garbage-collect the query-string directories not touched in
# the last 30 days (one level below .q/ and .qh/ of each host).
15 3 * * 0 find /srv/sites/cdn/public/_cache/*/.q /srv/sites/cdn/public/_cache/*/.qh -mindepth 1 -maxdepth 1 -type d -atime +30 -exec rm -rf {} +
```

## Rewriting origin references to the CDN

If you cannot change the origin's HTML directly, the cleanest option is an `.htaccess` rule on the origin that 301-redirects every static asset to the CDN:

``` apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} \.(css|js|jpe?g|png|gif|bmp|webp|ttf|woff2?|otf|eot|svg|ico)$ [NC]
    RewriteRule ^(.*)$ https://static.mycdn.com/$1 [R=301,L,QSA]
</IfModule>
```

`QSA` on the RewriteRule preserves the origin's query string, which is exactly what the proxy needs for correct QS-keyed caching.

For CMSes or custom frameworks, use their output-buffer / output-filter hook:

``` php
ob_start(function ($html) {
    return preg_replace_callback(
        '~(<img\s[^>]*\bsrc="|<link\s[^>]*\bhref=")(/[^"]+)~i',
        function ($m) {
            return $m[1] . 'https://static.mycdn.com' . $m[2];
        },
        $html
    );
});
```

## Security considerations

Read these before putting the proxy on the public Internet.

- **Host allowlist.** Only the hosts of `PROXY_HOSTS` have a cache directory; any other `Host` header is answered `421` and creates nothing, so a random `Host` cannot fill the disk, and a host never reads or purges the entries of another. `X-Forwarded-Host` is never read.
- **Public cache surface.** Any HTTP client can cause the proxy to create a new file under `public/_cache/` by requesting an arbitrary path that resolves on the origin. Negative caching mitigates the same-URL case and stays bounded (at most 4096 slot files in `var/negative/`), and query parameters outside `QUERY_STRING_ALLOWLIST` neither create entries nor reach the origin, but an attacker generating distinct random paths will still cause one origin fetch per request. Put a rate limiter in front of the CDN (nginx `limit_req`, Apache `mod_ratelimit`, fail2ban watching `index.php` invocations).
- **Circuit breaker per origin.** Even without a rate limiter, the breaker bounds origin exposure: once the origin keeps failing, no path is fetched from it for `NETWORK_ERROR_TTL` seconds, and the cached entries keep being served.
- **`SOURCE_ORIGIN_SERVER` bypasses front-facing protections.** Pinning the cURL connection to a specific `ip:port` bypasses the origin's DNS-based CDN/WAF. If you rely on the WAF for security, leave the variable empty.
- **`UPSTREAM_VERIFY_TLS` defaults to `true`** since 3.0 (2.x defaulted to `false`). An origin with a self-signed or mismatched certificate answers 503 after the upgrade: fix its certificate, or set `UPSTREAM_VERIFY_TLS=false` on a trusted network only. `composer check-env` prints a note while the key is unset.
- **`UPSTREAM_FOLLOW_REDIRECTS` defaults to `false`.** A redirect of the origin is then passed on to the client only when it stays below `SOURCE_ORIGIN` (rewritten to the proxy path); any other redirect is a `502`, so a compromised origin cannot send visitors to an internal host. With `true`, a redirect to another origin is followed only when every address of its host, after DNS, is public, and the connection is pinned to the checked address.
- **`CACHEABLE_EXTENSIONS` can leak sessions.** If you add `html`, `htm`, `xml`, `json` or `php` to the list and the origin ever serves authenticated content on those paths (private profile pages, signed JSON APIs, etc.), the proxy will cache that content (captured without the client's cookies and with no user context) and serve it indiscriminately. The `check-env` step (`bin/proxy-cdn check-env`) emits a warning when a sensitive extension appears in the list.
- **Purge needs a key** of `PURGE_KEYS`, scoped to the origin (see *Cache purge*); without a key every purge is refused.
- **Methods.** Only `GET`, `HEAD` and `OPTIONS` reach the cache (a valid CORS preflight, without credentials); `PURGE` is authenticated; every other method is a `405`, and `X-HTTP-Method-Override` is ignored.
- **SVG** responses carry `Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox`, so an SVG opened from the CDN origin runs no script. Every asset is sent with `X-Content-Type-Options: nosniff` and the media type of its extension, a miss included, whatever `Content-Type` the origin sent.
- **Paths** are decoded once and refused (`400`) when they could name another file: encoded `/` or `\`, `..` and dot segments, control characters, invalid UTF-8, or the reserved `_cache` and `_qs` prefixes.
- **`public/.htaccess` denies** direct access to dot-files, `composer.*`, `README.md`, `LICENSE`, project-internal directories (`vendor/`, `src/`, `bin/`, `logs/`, `.git/`) should the document root be mispointed at the project, the cache directory read directly (`_cache/`) and the buckets of 2.x (`_qs/`), atomicWrite tmp files (`.mftpcdn.*`), and the negative-cache markers of 2.x (`.404`, `.netfail`). Make sure `AllowOverride All` is in effect on the VirtualHost, otherwise none of those rules apply.
- **HSTS** is enabled with `max-age=31536000` only by default. The wider `includeSubDomains` / `preload` variants are commented out in `.htaccess` because they affect sibling hostnames on the same eTLD+1. Enable them only when you understand the blast radius.

## Troubleshooting

| Symptom                                     | Likely cause                                                                                                                                                                              |
|---------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `cwebp binary not available in PATH`        | `IMAGE_CONVERSION_MODE=native` but `cwebp` is missing. Install it or switch to `api` / `none`.                                                                                            |
| 404s for assets that do exist on the origin | A negative-cache marker in `var/negative/` is still warm. Wait `NEGATIVE_CACHE_TTL` seconds or send a `PURGE` for the URL.                                                                                          |
| 503 with `Retry-After: 30`                  | The circuit breaker of the origin is open (the origin failed `CIRCUIT_BREAKER_THRESHOLD` times in a row). Wait `NETWORK_ERROR_TTL` seconds for the probe, or delete `var/breaker/` once the origin is fixed.                                                                |
| 500 `Image conversion failed`               | Origin returned something cURL accepted as 200 but `cwebp` refused. Inspect `public/_cache/<host>/<path>` and `logs/proxy.log`.                                                                         |
| All responses HTTP (no HTTPS redirect)      | `mod_rewrite` off.                                                                                                                                                                        |
| Redirect loop behind a load balancer        | The TLS-terminating proxy is not listed as trusted in the web server (see *Web server configuration*), so its `X-Forwarded-Proto: https` is ignored.                                      |
| `421 Unknown host`                          | The `Host` header is not in `PROXY_HOSTS`.                                                                                                                                                |
| QS-keyed content always a cache miss        | QS parameters contain unique tokens per request (e.g. cache-breakers with timestamps). That is the expected behavior but it defeats the cache. Canonicalize on the origin before linking. |
| `logs/proxy.log` not appearing              | Web server user cannot write to project root. `chown -R www-data:www-data /srv/sites/cdn`.                                                                                                |

## License

BSD 3-Clause, see [LICENSE](LICENSE).
