# PHP Transparent Proxy CDN (with optional on-the-fly WEBP conversion)

A self-contained PHP application that turns a VirtualHost into a **self-hosted CDN** for the static assets of another site.

It acts as a **transparent proxy** between the origin server and the client's browser: on the first request a missing asset is fetched from the origin and cached on the local filesystem; on every subsequent request Apache serves the cached copy directly, without going through PHP. If the asset is a raster image (`jpg`, `jpeg`, `png`, `gif`, `bmp`) the proxy optionally generates a `.webp` variant alongside it and either permanent-redirects subsequent requests to the webp copy or serves it inline via content negotiation (see *Query string caching* below).

> Historical note: the repository name (`php-webp-rewriter-trasparent-proxy`) contains a typo kept for backward-compatibility. The project is a transparent proxy, not a "trasparent" one.

## Scope and honest positioning

This is a **small, hobbyist-to-small-production** project. It is appropriate for a narrow set of scenarios and has deliberate limits. Read this section before adopting it so you do not mistake it for something it is not.

**Use this project when:**

- You are on a **LAMP / LEMP** VPS, no ops team, no budget for a third-party CDN, and want WEBP conversion plus a filesystem cache in front of an origin you do not want to modify.
- Traffic is **moderate and predictable** (roughly: a personal site, a portfolio, a small business site, internal tools). Order of magnitude: up to a few dozen requests per second, not hundreds.
- **Simplicity wins over performance**: you want one `git clone`, one `composer install`, one `.env`, and you are willing to pay the cost of PHP being in the serving path for query-string-keyed hits.
- You want the deployment to be a single box you fully control (no external DNS dependency, no vendor account, no egress to someone else's network).

**Do NOT use this project when:**

- You need **production-grade CDN semantics**: Cloudflare's free tier (Polish for WEBP, cache, WAF, DDoS protection, anycast) does all of this, properly, for zero euros and five minutes of DNS work. Use that instead.
- You expect **hundreds of requests per second or more**, or traffic spikes from bots/scanners. PHP-FPM worker pools cap concurrency well below what `nginx proxy_cache` or Varnish deliver with a tenth of the memory.
- You need **horizontal scaling** behind a load balancer. The on-disk cache is per-instance; purges and warm-up do not propagate. Building shared state on top of this project is more work than starting from nginx/Varnish.
- You need **features this project deliberately does not implement**: Range request handling (partial content), Accept-Encoding negotiation (Brotli vs gzip), conditional requests past what Apache provides, size-capped or LRU cache eviction, metrics/observability endpoints, HTTP/2 push, HTTP/3.
- You need an **audited security posture**. This repository has no automated test suite and has not undergone a formal security review. The codebase has been manually hardened (atomic writes, circuit breaker, query-string caching, whitelist header mirroring, structured logging) but manual hardening is not the same as a track record in the wild.

**Real value-add of this project over the alternatives:**

The one thing this project does that is genuinely cumbersome to replicate with off-the-shelf tools is **on-the-fly WEBP conversion against an unmodifiable origin, with automatic 301 fallback for non-webp clients, served from an `.htaccess` + PHP stack**. If that specific combination matches your constraints, this is a reasonable starting point. Everything else (caching, HTTPS redirect, compression, ETags) is solved better elsewhere.

## Table of contents

- [Scope and honest positioning](#scope-and-honest-positioning)
- [Request lifecycle](#request-lifecycle)
- [Installation](#installation)
- [Configuration](#configuration)
- [Image conversion modes](#image-conversion-modes)
- [Cache purge](#cache-purge)
- [Query string caching](#query-string-caching)
- [Negative caching and circuit breaker](#negative-caching-and-circuit-breaker)
- [Content negotiation (Accept: image/webp)](#content-negotiation-accept-imagewebp)
- [Logging](#logging)
- [Cache maintenance](#cache-maintenance)
- [Rewriting origin references to the CDN](#rewriting-origin-references-to-the-cdn)
- [Security considerations](#security-considerations)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Request lifecycle

1. The client requests a web page of `https://origin.example.com`.
2. That page references a static asset as `https://cdn.example.com/images/whatever/resource.jpg` (instead of a relative path on the origin).
3. The CDN receives the request. Apache then **decides who handles it**:
    - **No query string** and the file **exists on disk**: Apache serves directly. PHP is not invoked.
    - **No query string** and the file is **missing on disk**: Apache rewrites to `index.php` (cache miss path).
    - **Any non-empty query string**: Apache always rewrites to `index.php`, because the cache key depends on the query string and Apache alone cannot compute it (see *Query string caching*).
4. `index.php` runs [public/index.php](public/index.php). Its flow is:
    1. Validate the request (traversal, extension whitelist).
    2. Compute the cache location.
        - Empty QS: `public/<path>`.
        - Non-empty QS: `public/_qs/<sha256_prefix>/<path>`.
    3. Answer an authenticated `PURGE` (see *Cache purge*); refuse the anonymous `?purge=` of 2.x with a 401.
    4. Short-circuit to 404 or 503 if a negative-cache marker is still warm.
    5. Serve from disk if the cache file exists (content-negotiated webp when applicable).
    6. Otherwise, fetch the origin over HTTPS with cURL, distinguish a real 404 from a transient network failure, persist the bytes atomically, optionally convert to webp, and stream the response.

## Installation

1. Clone this repo onto a LAMP/LEMP server and point a VirtualHost's `DocumentRoot` at `./public`.
2. Enable the required Apache modules: `sudo a2enmod rewrite headers expires deflate mime`.
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
| `SOURCE_ORIGIN_SERVER`            | `ip:port` | *optional*                | Pins the cURL connection to a specific `host:port`, bypassing DNS (and therefore any CDN/WAF in front of the origin). See security notes.                       |
| `CACHEABLE_EXTENSIONS`            | CSV       | `css,js,...,ico`          | Whitelist of file extensions the proxy will serve. Any extension outside this list is rejected with a 404.                                                      |
| `CACHE_EXPIRY`                    | seconds   | `31536000` (1y)           | `max-age` sent on first-hit responses and, via `.htaccess`, on cache hits. Combined with `must-revalidate` (not `immutable`), so a purge is effective.          |
| `NEGATIVE_CACHE_TTL`              | seconds   | `300`                     | How long a 404 marker is honored before the proxy re-queries the origin.                                                                                        |
| `NETWORK_ERROR_TTL`               | seconds   | `30`                      | Circuit-breaker window for transient upstream failures (timeout, DNS, TLS, 5xx).                                                                                |
| `UPSTREAM_TIMEOUT`                | seconds   | `30`                      | Hard cap on a single upstream fetch. Combined with `CURLOPT_LOW_SPEED_LIMIT=1024` + `_TIME=10`, this also aborts stalled transfers. A stalled DNS lookup is bounded by the system resolver instead. |
| `UPSTREAM_CONNECT_TIMEOUT`        | seconds   | `5`                       | Socket-level connect timeout.                                                                                                                                   |
| `UPSTREAM_VERIFY_TLS`             | bool      | `true`                    | Verifies the origin TLS certificate and host name. 2.x defaulted to `false`; `check-env` notes the change when the key is unset and the origin is https.        |
| `UPSTREAM_FOLLOW_REDIRECTS`       | bool      | `false`                   | If `true`, cURL follows 30x responses. Default off: a compromised origin that redirects to an internal host would turn the proxy into an SSRF vector.           |
| `QUERY_STRING_ALLOWLIST`          | CSV       | `v,ver,version`           | Query parameters that key the cache and reach the origin, by decoded name and letter case; `*` keeps every parameter (as 2.x did), `none` keeps none.          |
| `QUERY_STRING_MODE`               | enum      | `ignore`                  | `ignore` drops the other parameters from the cache key and the origin request; `reject` answers 400 `Query parameter not allowed`.                              |
| `PURGE_KEYS`                      | CSV       | *empty*                   | `scope=secret` pairs that authenticate a `PURGE` (see *Cache purge*); the scope is the origin host name or `*`. Empty disables purging.                          |
| `TRUSTED_PROXIES`                 | CSV       | *empty*                   | IPs and CIDR blocks (IPv4, IPv6) of the reverse proxies whose `X-Forwarded-Proto` and `X-Forwarded-For` are believed; same key and syntax as the conversion API. Empty trusts nobody. |
| `IMAGE_CONVERSION_MODE`           | enum      | `none`                    | One of `native`, `api`, `none`.                                                                                                                                 |
| `WEBP_API_SERVER`                 | URL       | `null`                    | Only used when mode = `api`.                                                                                                                                    |
| `WEBP_API_KEY`                    | string    | `null`                    | Only used when mode = `api`.                                                                                                                                    |
| `CHARSET` / `TIMEZONE` / `LOCALE` | string    | `UTF-8` / `UTC` / `en_US` | Standard PHP locale setup.                                                                                                                                      |

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
- **HMAC**: `X-Purge-Timestamp: <unix seconds>` and `X-Purge-Signature: <hex HMAC-SHA256>` of `PURGE\n<host>\n<path and query>\n<timestamp>` under the secret, where `<host>` is the `Host` header in lower case (with its port, when not the default one) and `<path and query>` the request target as sent. The timestamp must be within 300 seconds of the proxy clock; within that window a signature can be replayed, which only repeats the same purge.

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

A purge removes the entry of that exact URL (the `_qs/<hash>/` bucket of its allowlisted query string, or the path alone), the `.webp` variant converted from a raster entry, and what the negative cache and the circuit breaker remember for it. It does not fetch the asset again: the next request does. The answer is `200` with `{"purged":true,"path":"/images/hero.jpg","query":"v=42","removed":2}`; a request without a valid key gets `401` with `WWW-Authenticate: Bearer realm="proxy-cdn purge"`. Every purge is logged at `notice` level with the client address (read through `TRUSTED_PROXIES`); every refusal at `warning` level.

The anonymous `?purge=1` and `?purge_all=1` of 2.x were removed: anyone could empty the cache and send the load to the origin. Such a request now gets a `401` whose body explains the `PURGE` request, and purges nothing. `purge_all` also removed the files of other URLs that share the base name (`hero.png` for `hero.jpg`); purge those by their own URL.

## Query string caching

Cache-busted URLs (e.g. `/hero.jpg?v=42`) are a common origin idiom. A naive disk cache keyed on path alone would pin the very first version the proxy saw and never re-fetch. To avoid that:

Only the parameters named in `QUERY_STRING_ALLOWLIST` (default `v,ver,version`) count. The others, such as `utm_source` or a random `?_=<timestamp>`, are left out of the cache key and of the origin request (`QUERY_STRING_MODE=ignore`, the default) or refused with a 400 (`QUERY_STRING_MODE=reject`). In 2.x every distinct query string was a cache entry and an origin fetch of its own, so random query strings filled the disk and bypassed the cache.

- A query string with **no allowlisted parameter** keys on the path: `public/<path>`. Apache serves directly on cache hit, zero PHP; when the request reaches PHP (because it carries a query string) the stored file is served from disk.

- Otherwise the allowlisted parameters, sorted by name (repeated names keep their order, escapes in upper case), are hashed with SHA-256; the first 64 bits of the hash become a subdirectory under `public/_qs/`:

  ``` text
  /hero.jpg?v=1&ver=2         -> public/_qs/<sha256_prefix>/hero.jpg
  /hero.jpg?ver=2&utm_x=1&v=1 -> same file (key v=1&ver=2)
  /hero.jpg?v=42              -> public/_qs/<other_hash>/hero.jpg
  ```

  The origin receives the allowlisted parameters as the client wrote them, in their order (`?ver=2&v=1` above); 2.x forwarded its canonical form, which turned `a.b=1` into `a_b=1` and `v` into `v=`.

  Apache always routes such requests through `index.php` (the rewrite rule matches `QUERY_STRING .`), because it cannot compute the SHA-256 on its own. `index.php` reads or writes the correct bucket and serves.

Direct HTTP access to `/_qs/...` is denied with 403 in `.htaccess`: the bucket layout is an internal implementation detail.

Pros: every origin version is preserved as long as its query string is unique, and purges apply to the exact bucket.

Cons: on a QS-keyed hit, PHP runs (no pure-Apache fast path). For a static asset this is still fast (opcode cache, file_get_contents), but it is not free.

## Negative caching and circuit breaker

The proxy remembers two kinds of failure. A `404` is remembered in `var/negative/` of the project, outside the document root: one flat directory of at most 4096 slot files (`<3 hex digits of the SHA-256 of the entry>.neg`), so a flood of random paths creates no directory and no file below `public/` (in 2.x every missing path created its directories and a `<path>.404` marker). Two entries that share a slot replace each other's marker, which only costs one more origin request. The `.netfail` marker of the circuit breaker lives next to the cache file and is hidden from HTTP by a `FilesMatch "\.(404|netfail)$"` `Require all denied` rule in [public/.htaccess](public/.htaccess).

| Marker           | Armed when origin returns...                                                                                     | TTL                                  | HTTP status served to the client |
|------------------|------------------------------------------------------------------------------------------------------------------|--------------------------------------|----------------------------------|
| `var/negative/`  | A semantic `404 Not Found`                                                                                       | `NEGATIVE_CACHE_TTL` (default 300 s) | `404`                            |
| `<path>.netfail` | `5xx`, connection refused, DNS failure, TLS error, timeout, transfer stalled under the low-speed-limit threshold | `NETWORK_ERROR_TTL` (default 30 s)   | `503` + `Retry-After`            |

Rationale:

- A real `404` is a persistent signal. 5 minutes of suppression prevents a single missing favicon requested a million times from hammering the origin a million times.
- A network blip is a transient signal. 30 seconds of suppression is a tiny circuit breaker that protects the PHP worker pool (every upstream fetch uses one) without permanently hiding assets that are actually fine.

On a successful re-fetch both markers are dropped. A `PURGE` also clears them.

## Content negotiation (Accept: image/webp)

For **QS-less** requests the `.htaccess` does the classic trick: `request for foo.jpg` + `foo.webp exists on disk` -\> `301 -> foo.webp`.

For **QS-keyed** requests Apache routes through PHP; the decision then is driven by the client's `Accept` header:

- Client sends `Accept: image/webp` (Chrome, Firefox, Safari, Edge on modern versions): the proxy serves the `.webp` sibling if it exists (or generates it lazily on the first webp-capable hit).
- Client does not advertise webp: the proxy serves the original raster.
- In both cases the response carries `Vary: Accept` so intermediaries do not cross-pollute cached variants.

A bare `*/*` is intentionally NOT treated as webp support, because old UAs send it and then fail to decode the response.

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
# Every hour: drop .netfail markers older than 1 day so that
# resources which genuinely came back to life become retryable. The
# negative cache in var/negative/ is bounded and needs no cleaning;
# '*.404' files are markers left behind by 2.x.
0 * * * * find /srv/sites/cdn/public \( -name '*.404' -o -name '*.netfail' \) -mtime +1 -delete

# Every week: garbage-collect QS buckets not touched in the last 30 days.
# The _qs/ layout is flat (one level of hash-dirs), so this is cheap.
15 3 * * 0 find /srv/sites/cdn/public/_qs -mindepth 1 -maxdepth 1 -type d -atime +30 -exec rm -rf {} +
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

- **Public cache surface.** Any HTTP client can cause the proxy to create a new file under `public/` by requesting an arbitrary path that resolves on the origin. Negative caching mitigates the same-URL case, but an attacker generating distinct random paths will still cause one origin fetch per request. Put a rate limiter in front of the CDN (nginx `limit_req`, Apache `mod_ratelimit`, fail2ban watching `index.php` invocations).
- **Upstream-failure marker as an extra circuit breaker.** Even without a rate limiter, the `.netfail` marker bounds origin exposure: once the origin starts misbehaving, each path auto-suppresses for `NETWORK_ERROR_TTL` seconds.
- **`SOURCE_ORIGIN_SERVER` bypasses front-facing protections.** Pinning the cURL connection to a specific `ip:port` bypasses the origin's DNS-based CDN/WAF. If you rely on the WAF for security, leave the variable empty.
- **`UPSTREAM_VERIFY_TLS` defaults to `true`** since 3.0 (2.x defaulted to `false`). An origin with a self-signed or mismatched certificate answers 503 after the upgrade: fix its certificate, or set `UPSTREAM_VERIFY_TLS=false` on a trusted network only. `composer check-env` prints a note while the key is unset.
- **`UPSTREAM_FOLLOW_REDIRECTS` defaults to `false`.** A compromised origin that returns a 30x to an internal host would otherwise become an SSRF pivot. Enable redirects only when the origin legitimately uses them.
- **`CACHEABLE_EXTENSIONS` can leak sessions.** If you add `html`, `htm`, `xml`, `json` or `php` to the list and the origin ever serves authenticated content on those paths (private profile pages, signed JSON APIs, etc.), the proxy will cache that content (captured without the client's cookies and with no user context) and serve it indiscriminately. The `check-env` step (`bin/proxy-cdn check-env`) emits a warning when a sensitive extension appears in the list.
- **Purge is unauthenticated** (see above).
- **`public/.htaccess` denies** direct access to dot-files, `composer.*`, `README.md`, `LICENSE`, project-internal directories (`vendor/`, `src/`, `bin/`, `logs/`, `.git/`, and `var/` at the root), the internal QS-bucket layout (`_qs/`), atomicWrite tmp files (`.mftpcdn.*`), and negative-cache markers (`.404`, `.netfail`). Make sure `AllowOverride All` is in effect on the VirtualHost, otherwise none of those rules apply.
- **HSTS** is enabled with `max-age=31536000` only by default. The wider `includeSubDomains` / `preload` variants are commented out in `.htaccess` because they affect sibling hostnames on the same eTLD+1. Enable them only when you understand the blast radius.

## Troubleshooting

| Symptom                                     | Likely cause                                                                                                                                                                              |
|---------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `cwebp binary not available in PATH`        | `IMAGE_CONVERSION_MODE=native` but `cwebp` is missing. Install it or switch to `api` / `none`.                                                                                            |
| 404s for assets that do exist on the origin | A negative-cache marker in `var/negative/` is still warm. Wait `NEGATIVE_CACHE_TTL` seconds or send a `PURGE` for the URL.                                                                                          |
| 503 with `Retry-After: 30`                  | A `.netfail` marker is warm (origin was unreachable recently). Either wait or re-check the origin and send a `PURGE` for the URL.                                                                |
| 500 `Image conversion failed`               | Origin returned something cURL accepted as 200 but `cwebp` refused. Inspect `public/<path>` and `logs/proxy.log`.                                                                         |
| All responses HTTP (no HTTPS redirect)      | `mod_rewrite` off or the VirtualHost is behind a TLS-terminating proxy that does not forward `X-Forwarded-Proto: https`.                                                                  |
| QS-keyed content always a cache miss        | QS parameters contain unique tokens per request (e.g. cache-breakers with timestamps). That is the expected behavior but it defeats the cache. Canonicalize on the origin before linking. |
| `logs/proxy.log` not appearing              | Web server user cannot write to project root. `chown -R www-data:www-data /srv/sites/cdn`.                                                                                                |

## License

BSD 3-Clause, see [LICENSE](LICENSE).
