# PHP Transparent Proxy CDN

[![CI](https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy/actions/workflows/ci.yml/badge.svg)](https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-7.2.5%20to%208.5-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE)

`mfonte/proxy-cdn` is a self-hosted image proxy and CDN for the static assets of another site. It sits in front of an origin you do not have to modify: the first request for an asset fetches it from the origin and stores it on disk, and later requests are served by Apache or nginx straight from that file, without PHP. With a converter configured, raster images get AVIF and WEBP variants that are served at the same URL to the clients that accept them; text assets get Brotli and gzip encodings.

The repository name (`php-webp-rewriter-trasparent-proxy`) keeps a historical spelling. The Composer package is `mfonte/proxy-cdn`, and this README describes version 3.0.

## Contents

- [Features](#features)
- [When to use it](#when-to-use-it)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Image conversion](#image-conversion)
- [Purge](#purge)
- [Several origins](#several-origins)
- [Running several instances](#running-several-instances)
- [Deployment](#deployment)
- [Operations](#operations)
- [Pointing a site at the proxy](#pointing-a-site-at-the-proxy)
- [Security model](#security-model)
- [Known limitations](#known-limitations)
- [Performance](#performance)
- [Upgrading from 2.x](#upgrading-from-2x)
- [Testing and development](#testing-and-development)
- [Versioning and compatibility](#versioning-and-compatibility)
- [Contributing](#contributing)
- [Reporting a vulnerability](#reporting-a-vulnerability)
- [License](#license)

## Features

- **Hits served by the web server.** Apache (`public/.htaccess`) and nginx (`config/nginx/`) locate the cache file of a request themselves, negotiated variant and encoding included, and serve it with the headers PHP would send. PHP handles misses, revalidations and the requests the web server cannot map.
- **Content negotiation at one URL.** AVIF, then WEBP, then the original, chosen from `Accept`, with `Vary: Accept`; Brotli or gzip chosen from `Accept-Encoding`.
- **Image conversion** with `IMAGE_CONVERTER`: `local` (the conversion library in this host), `remote` (the conversion API, with an optional local fallback) or `none`. Variants are made after the response, and every output is verified before it is stored.
- **Signed resize URLs**: `/_resize/<width>/<signature>/<path>`, HMAC-SHA256 with `RESIZE_KEY`, limited to the widths of `RESIZE_WIDTHS`.
- **HTTP cache semantics** of a shared cache (RFC 9111): long-lived entries are kept for the web server; short-lived ones are served and revalidated by PHP, with `stale-while-revalidate` and `stale-if-error`.
- **Request coalescing**: a burst of requests on a cold URL costs one origin fetch, also when the origin fails or redirects.
- **Origin protection**: a circuit breaker per origin, a bounded negative cache for `404` and `410`, a query-string allowlist, and an optional miss rate limit per client.
- **Authenticated purge**: `PURGE` with a Bearer key or an HMAC, scoped per origin; `bin/proxy-cdn cache:purge`; fan-out to the other instances of a cluster.
- **Several origins** by `Host` header, each with its own cache directory.
- **Operations**: `/healthz`, Prometheus `/metrics`, JSON logs with a request ID, `check-env`, `cache:warmup`, `cache:evict` (LRU to a size cap) and `cache:migrate-2x`.
- **Embeddable**: `ProxyKernel` turns a Symfony `Request` into a `Response`, and can be mounted in another application.
- Tested on PHP 7.2 to 8.5, with Apache and nginx integration tests in CI.

## When to use it

The proxy suits a single server, or a few, under your control: a LAMP or LEMP host in front of an origin you cannot or do not want to change, where you want image conversion and a disk cache without a third-party CDN or a cache daemon. Hits come close to the cost of a static file (see [Performance](#performance)); misses, revalidations and purges run through PHP-FPM, whose worker pool bounds the concurrency.

Consider something else when you need a global edge network, DDoS protection or a WAF; very high request rates on misses, where a dedicated cache such as nginx `proxy_cache` or Varnish uses fewer resources; or a cache shared between instances. Instances can run side by side and share purges (see [Running several instances](#running-several-instances)), but each keeps its own cache.

## How it works

```text
client ──> Apache / nginx ──(cache file exists)──> served from public/_cache/<host>/...
                 │
                 └──(anything else)──> public/index.php ──> origin
                                         │
                                         ├─ store the asset (web-server tier or managed tier)
                                         ├─ answer the client
                                         └─ after the response: variants, revalidation, purge fan-out
```

Entries are stored in `public/_cache/<host>/`, in two tiers:

- **The web-server tier** holds the entries whose origin lifetime is unknown or at least `CACHE_REVALIDATE_BELOW` (one hour by default). Apache and nginx serve them until they are purged or evicted.
- **The managed tier**, below `<host>/.m/`, holds the shorter-lived entries with their metadata. Only PHP serves them, and revalidates them with the origin once they expire.

Variants, encodings and query-string buckets live in dot directories next to the entry (`.avif/`, `.webp/`, `.br/`, `.gz/`, `.q/`, `.qh/`), which no request path can name. A new raster image waits in `.pending/`, with its variants staged next to it, until they are made; PHP serves it meanwhile, and then it is published to the web-server tier with one `rename()`.

The proxy state (negative cache, locks, circuit breakers, rate-limit buckets, metrics, conversion state) lives in `var/`, outside the document root, and is bounded by design. [docs/caching.md](docs/caching.md) describes the layout, the tiers, the negative cache, the circuit breaker and request coalescing in detail.

## Requirements

- **PHP 7.2.5 to 8.5**, with ext-filter, ext-hash and ext-json.
- **ext-curl**, which `check-env` requires. It gives HTTP/2 and connection reuse to the origin, and is needed for the conversion API and for parallel warm-up and purge fan-out. Without it the proxy falls back to PHP streams, which need ext-openssl for https origins.
- Optional: ext-zlib (gzip encodings, gzip origin bodies), ext-brotli (Brotli encodings), ext-posix (process isolation of local conversions), and for `IMAGE_CONVERTER=local` a conversion backend: the gd or imagick extension, or `cwebp`, `avifenc` or `vips` in `PATH`.
- **Apache 2.4** with `mod_rewrite`, `mod_headers`, `mod_setenvif`, `mod_env`, `mod_mime`, `mod_ssl` and `mod_proxy_fcgi` (or mod_php), or **nginx** (1.25.1 or later for the shipped `http2 on`), with PHP-FPM.
- Composer 2. The proxy is not published on Packagist: Composer resolves its image library, `mfonte/secure-image-converter`, from the GitHub repository declared in `composer.json`.

## Installation

Clone the repository at a release tag and install the dependencies for production:

```bash
git clone https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy.git /srv/proxy-cdn
cd /srv/proxy-cdn
git checkout <release tag>
composer install --no-dev --classmap-authoritative
cp .env.example .env
```

The Composer `post-install-cmd` hook runs `bin/proxy-cdn check-env --informational`, which reports configuration problems without failing the install. No lock file is committed: Composer resolves the dependencies for the PHP version that runs it.

To embed the kernel in another application instead (see [Embedding](docs/deployment.md#embedding-the-kernel-in-another-application)), declare both VCS repositories in the root `composer.json`, since Composer reads repositories from the root package only:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mauriziofonte/php-webp-rewriter-trasparent-proxy" },
        { "type": "vcs", "url": "https://github.com/mauriziofonte/php-secure-image-converter" }
    ]
}
```

Then require `mfonte/proxy-cdn` at a release tag, and `mfonte/secure-image-converter` with the constraint given in the proxy's `composer.json`.

## Quick start

1. Edit `.env`. The minimum is the origin, the host names the proxy answers for, and the extensions it serves:

   ```dotenv
   SOURCE_ORIGIN = "https://www.example.com"
   PROXY_HOSTS = "cdn.example.com"
   CACHEABLE_EXTENSIONS = "css,js,jpg,jpeg,png,gif,webp,svg,woff2,ico"
   CACHE_EXPIRY = 31536000
   ```

   Review the other keys of `.env.example`. In particular, remove `SOURCE_ORIGIN_SERVER` unless you mean to pin the origin's address, and set `IMAGE_CONVERTER` to `none` if this host has no conversion backend.

2. Validate it:

   ```bash
   php bin/proxy-cdn check-env
   ```

3. Configure the web server with `public/` as the document root: Apache from [config/apache/proxy-cdn.conf](config/apache/proxy-cdn.conf), or nginx from [config/nginx/proxy-cdn.conf](config/nginx/proxy-cdn.conf) (see [Deployment](#deployment)). Make `public/_cache/`, `var/` and `logs/` writable by the PHP-FPM user.

4. Request an asset twice:

   ```bash
   curl -sI https://cdn.example.com/images/logo.png | grep -i x-cache-status   # MISS: fetched by PHP
   curl -sI https://cdn.example.com/images/logo.png | grep -i x-cache-status   # HIT: served by the web server
   ```

5. Reference the proxy from the site's pages (see [Pointing a site at the proxy](#pointing-a-site-at-the-proxy)).

## Configuration

Every setting is read from the PHP environment first and from `.env` second; an empty value or `null` means "not set". The values are validated before any request is answered, with the rules `check-env` applies; an invalid configuration answers every request with `503 ENV_FILE_LOAD_ERR`. The configuration is compiled into `.config.cache.php` (mode `0600`) and recompiled automatically when its sources change.

The most common keys:

| Key | Default | Meaning |
| --- | --- | --- |
| `SOURCE_ORIGIN` | required | The origin URL. |
| `PROXY_HOSTS` | required | The host names the proxy answers for, optionally each with its own origin. |
| `CACHEABLE_EXTENSIONS` | required | The extensions the proxy serves. |
| `CACHE_EXPIRY` | `86400` | The `max-age` PHP sends; keep it equal to the one of the web server configurations (`31536000`). |
| `CACHE_REVALIDATE_BELOW` | `3600` | Origin lifetimes below this go to the managed tier, revalidated by PHP. |
| `QUERY_STRING_ALLOWLIST` | `v,ver,version,id` | The query parameters that key the cache and reach the origin. |
| `UPSTREAM_VERIFY_TLS` | `true` | Verifies the origin certificate. |
| `TRUSTED_PROXIES` | not set | The reverse proxies whose `X-Forwarded-*` headers are believed. |
| `PURGE_KEYS` | not set | The keys that authenticate a purge. |
| `IMAGE_CONVERTER` | `none` | `none`, `local` or `remote`. |
| `SENDFILE` | `none` | `x-accel-redirect` (nginx) or `x-sendfile` (Apache) to let the web server send the files PHP chooses. |

[docs/configuration.md](docs/configuration.md) lists every key, with its default, its accepted values and its meaning.

## Image conversion

Each raster entry (`jpg`, `jpeg`, `png`, `gif`, `bmp`) gets the variants of `IMAGE_FORMATS` (`avif,webp` by default), made by the converter that `IMAGE_CONVERTER` selects:

- **`none`** (the default): no variants; the originals are served.
- **`local`**: [mfonte/secure-image-converter](https://github.com/mauriziofonte/php-secure-image-converter) converts in this host, with the backends it finds (the gd or imagick extension, `vips`, `cwebp`, `avifenc`) and the process isolation it can set up. ext-posix is needed for the isolation and for the library's cache of what it detects, in `var/converter-cache/`. `check-env` warns when no backend or no ext-posix is found.
- **`remote`**: the image is posted to the [conversion API](https://github.com/mauriziofonte/php-image-to-webp-conversion-api) v2 (`POST /v2/convert`), with `IMAGE_API_URL` and `IMAGE_API_KEY` (sent as `Authorization: Bearer`). The API's capabilities and limits are read from its `GET /healthz` and kept for five minutes; an image the API would refuse (too large, a format it does not accept) is not sent. The API certificate is always verified (`IMAGE_API_CA_FILE` for a private CA), and redirects are never followed. An API that cannot convert (unreachable, `503`, rate limited) opens a circuit breaker in `var/conversion-api/`.
- **`remote` with `IMAGE_API_FALLBACK=true`**: while the API cannot convert, the conversion is made locally. A conversion the API refused because of the image is not tried again locally.

```dotenv
IMAGE_CONVERTER = "remote"
IMAGE_API_URL = "https://images-api.example.com"
IMAGE_API_KEY = "the key the API gave you"
```

A conversion that fails never fails the request: the original is served, the failure is logged with its error code, and the variant is not tried again for a day when the image itself failed, for 300 seconds when the converter does not support the format, and for `NETWORK_ERROR_TTL` seconds when the converter could not convert now. The request that stores a new image answers with the original and `Cache-Control: public, max-age=0, must-revalidate`; PHP serves the image from the pending tier until its variants are settled, and then the web server serves it and its variants. The variants are made after the response, each within `IMAGE_CONVERSION_TIMEOUT`. [Image variants and the pending tier](docs/caching.md#image-variants-and-the-pending-tier) gives the details.

### Signed resize URLs

With `RESIZE_WIDTHS` (up to 16 widths), `RESIZE_KEY` (32 characters or more) and a converter, the proxy serves resized copies of raster images:

```text
/_resize/<width>/<signature>/<path of the image>[?<query string>]
```

The image is resized to `<width>` pixels wide, never enlarged, in its own format and with its own variants. `<signature>` is the first 32 hex digits of:

```text
HMAC-SHA256(RESIZE_KEY, "resize\n<host>\n<width>\n<path>\n<query>")
```

where `<host>` is the proxy host name in lower case without port, `<path>` the decoded path of the image, and `<query>` its canonical query string (the allowlisted parameters, sorted; empty for none). A URL that is not signed, or whose width is not listed, gets `403 Resize URL refused` without any request to the origin. For example, in the application that renders the pages:

```php
$message = "resize\n" . 'cdn.example.com' . "\n" . 320 . "\n" . '/images/photo.jpg' . "\n" . '';
$signature = substr(hash_hmac('sha256', $message, $resizeKey), 0, 32);
$url = 'https://cdn.example.com/_resize/320/' . $signature . '/images/photo.jpg';
```

A resized image is stored at the path of its resize URL, where the web server serves it, until it is purged; a purge of the image also removes its resized copies. When the converter cannot resize now, the image is answered as it is, without being stored.

## Purge

A purge is a `PURGE` request for the asset URL that proves a key of `PURGE_KEYS`. Each key is a `scope=secret` pair: the scope is the host name of an origin (the host of `SOURCE_ORIGIN`, or of an origin in `PROXY_HOSTS`) or `*` for every origin, and the secret has 16 or more printable ASCII characters without spaces or commas. Without keys, every purge is refused.

```dotenv
PURGE_KEYS = "www.example.com=0f6c2e7d9a1b4c58e3f2a7d6b9c0e1f2"
```

A request proves a key in one of two ways:

- **Bearer**: `Authorization: Bearer <secret>`. The secret travels with the request: use it over HTTPS only.
- **HMAC**: `X-Purge-Timestamp: <unix seconds>` and `X-Purge-Signature: <hex HMAC-SHA256>` of `PURGE\n<host>\n<path and query>\n<timestamp>` under the secret, where `<host>` is the `Host` header in lower case (with its port when it is not the default one) and `<path and query>` the request target as sent. The timestamp must be within 300 seconds of the proxy clock.

```bash
# Bearer
curl -X PURGE -H 'Authorization: Bearer 0f6c2e7d9a1b4c58e3f2a7d6b9c0e1f2' \
    'https://cdn.example.com/images/hero.jpg?v=42'

# HMAC
ts=$(date +%s)
sig=$(printf 'PURGE\ncdn.example.com\n/images/hero.jpg?v=42\n%s' "$ts" |
    openssl dgst -sha256 -hmac '0f6c2e7d9a1b4c58e3f2a7d6b9c0e1f2' -r | cut -d' ' -f1)
curl -X PURGE -H "X-Purge-Timestamp: $ts" -H "X-Purge-Signature: $sig" \
    'https://cdn.example.com/images/hero.jpg?v=42'
```

A purge removes the entry of the URL with its metadata, its variants, its encodings, its pending copy and staged variants, in both tiers, and what the negative cache and request coalescing remember for it. A purge of a URL **without** a query string also removes the entries of every query string of the same path (`/app.css?v=1`, `/app.css?v=2`, ...); a purge of a URL with a query string removes that entry only. It fetches nothing: the next request does. The answer is `200` with:

```json
{"purged":true,"path":"/images/hero.jpg","query":"v=42","removed":2}
```

A request without a valid key gets `401` with `WWW-Authenticate: Bearer realm="proxy-cdn purge"`. Every purge is logged at `notice` level with the client address, and every refusal at `warning` level. The anonymous `?purge=1` and `?purge_all=1` of 2.x purge nothing: they get a `401` whose body explains the `PURGE` request.

From the command line, `bin/proxy-cdn cache:purge <url>...` purges as an authenticated `PURGE` would, on this instance and then on every peer of `CLUSTER_PEERS` (see [Operations](docs/operations.md#cachepurge)).

## Several origins

One installation can serve several sites: each entry of `PROXY_HOSTS` is a host name, optionally with its own origin.

```dotenv
SOURCE_ORIGIN = "https://www.example.com"
PROXY_HOSTS = "cdn.example.com, static.example.org=https://www.example.org"
```

`cdn.example.com` fetches from `SOURCE_ORIGIN` and `static.example.org` from `https://www.example.org`. Each host has its own cache directory (`public/_cache/<host>/`), each origin its own circuit breaker, and purge keys are scoped to an origin, so the tenants of one installation cannot read or purge each other's entries. Any other `Host` header gets `421 Unknown host` and creates nothing on disk. Name every host in the web server configuration too (Apache `ServerAlias`; the nginx `server_name _` serves them all). `SOURCE_ORIGIN_SERVER` cannot be combined with several origins.

## Running several instances

A single box is the default, and needs none of this. To scale horizontally, run several instances behind a load balancer and list the others in `CLUSTER_PEERS` of each:

```dotenv
CLUSTER_PEERS = "https://node-b.internal:8443, https://node-c.internal:8443"
CLUSTER_CA_FILE = "/etc/proxy-cdn/ca.pem"
```

A `PURGE` that an instance accepts is then sent, after its own response, to every peer: `PURGE https://<peer><path and query>` with the original `Host`, the request ID, `X-Purge-Fanout: 1` and an HMAC under the purge key of the origin (the message above, followed by `\nfanout\n` and the SHA-256 of the empty body). Every instance must hold the same `PURGE_KEYS`. A peer accepts that request with the HMAC only and never sends it on, so purges cannot loop. Peers are reached over HTTPS, their certificate always verified, without redirects, up to 16 at a time, each within `CLUSTER_TIMEOUT` seconds. A peer that fails is logged (`peer purge failed`) and counted in `/metrics`; the local purge stands, and `cache:purge` exits with `2`. Expose the peer port to the other instances only.

Each instance keeps its own `public/_cache` and `var/`; never share them between instances. Request coalescing, the circuit breaker, the miss rate limit, `cache:warmup` and `cache:evict` work per instance. [docker/cluster/](docker/cluster/) is a working example with two instances, a load balancer and a private CA; [Deployment](docs/deployment.md#several-instances-behind-a-load-balancer) explains how to run it.

## Deployment

[docs/deployment.md](docs/deployment.md) covers the production setup:

- **Apache**: the two virtual hosts of [config/apache/proxy-cdn.conf](config/apache/proxy-cdn.conf), `:443` and `:80`, both including [public/.htaccess](public/.htaccess) with `AllowOverride None`.
- **nginx**: the files of [config/nginx/](config/nginx/), copied to `/etc/nginx/proxy-cdn/`, with one server block on `:80` and `:443`.
- **PHP-FPM**: [config/php/proxy-cdn.ini](config/php/proxy-cdn.ini) (OPcache, and preloading with [config/php/preload.php](config/php/preload.php) on PHP 7.4 and later), in a pool and as a user of its own.
- **Trusted proxies**: behind a TLS-terminating load balancer, list its addresses in `TRUSTED_PROXIES` and in the web server (both Apache virtual hosts, or the nginx `geo` block), so that its `X-Forwarded-Proto: https` stops the HTTPS redirect.
- **Sending files**: `SENDFILE` with `X-Accel-Redirect` or `mod_xsendfile`.
- **Embedding** `ProxyKernel` in another application, as the CI does in a Laravel application.

Both web servers must give PHP longer than the request coalescing timeout to answer, which is 60 seconds with the defaults and up to 210 seconds with resize URLs, the conversion API and its fallback: the shipped configurations set 300 seconds.

## Operations

| Command | Purpose |
| --- | --- |
| `bin/proxy-cdn check-env` | Validates the configuration and the host; exits non-zero on any error. |
| `bin/proxy-cdn cache:purge <url>...` | Purges URLs here and on `CLUSTER_PEERS`; `--local`, or `--all --host=<host>`. |
| `bin/proxy-cdn cache:warmup <sitemap or list>` | Requests the URLs of a sitemap or a list through the proxy. |
| `bin/proxy-cdn cache:evict` | Brings the cache down to `CACHE_MAX_SIZE`, least recently used first. |
| `bin/proxy-cdn cache:migrate-2x` | Moves or deletes the cache that 2.x left in `public/`. |

- `GET /healthz` answers `200 {"status":"ok"}`, or `503` when the cache or state directory cannot be written. It never contacts the origin.
- `GET /metrics` serves Prometheus counters to `Authorization: Bearer <key>` of `METRICS_KEYS`. It counts the requests PHP answers; count the web server's hits from its access log, whose format the shipped configurations include. `METRICS_STORE=off` turns the counters off.
- Every response PHP sends carries `X-Request-Id`, and every JSON log line of the request carries the same `request_id`; a valid client value is adopted.
- `MISS_RATE_LIMIT` caps the origin fetches one client may cause per minute (`429` above it); hits are never limited.
- `EARLY_HINTS` sends a `103 Early Hints` before a miss is fetched, where PHP can (FrankenPHP).

[docs/operations.md](docs/operations.md) documents each command, the metrics, the log format, scheduled maintenance and troubleshooting.

## Pointing a site at the proxy

The site's pages must reference the proxy host instead of the origin. When the HTML cannot be changed directly, an Apache rule on the origin can redirect every static asset to the proxy (`QSA` keeps the query string, which the proxy needs to key versioned assets):

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} \.(css|js|jpe?g|png|gif|bmp|webp|ttf|woff2?|otf|eot|svg|ico)$ [NC]
    RewriteRule ^(.*)$ https://cdn.example.com/$1 [R=301,L,QSA]
</IfModule>
```

In an application, rewrite the asset URLs when the page is rendered, for example with an output buffer:

```php
ob_start(function ($html) {
    return preg_replace_callback(
        '~(<img\s[^>]*\bsrc="|<link\s[^>]*\bhref=")(/[^"]+)~i',
        function ($m) {
            return $m[1] . 'https://cdn.example.com' . $m[2];
        },
        $html
    );
});
```

If the build tool versions assets with a query parameter other than `v`, `ver`, `version` or `id`, add it to `QUERY_STRING_ALLOWLIST`.

## Security model

The proxy is a public, unauthenticated cache in front of an origin. Its defenses, each covered by the test suite:

- **Host allowlist.** Only the hosts of `PROXY_HOSTS` have a cache directory; any other `Host` gets `421` and creates nothing. `X-Forwarded-Host` is never read.
- **Paths.** A request path is decoded once and refused (`400`) when it could name another file: encoded `/` or `\`, dot segments and any segment starting with a dot, control characters, invalid UTF-8, segments over 255 bytes, and the reserved `_cache` and `_qs` prefixes. Only the extensions of `CACHEABLE_EXTENSIONS` are served.
- **Methods.** `GET`, `HEAD` and `OPTIONS` (a CORS preflight without credentials) reach the cache; `PURGE` is authenticated; every other method gets `405`, and `X-HTTP-Method-Override` is ignored.
- **Query strings.** Parameters outside `QUERY_STRING_ALLOWLIST` neither key the cache nor reach the origin, so random query strings cannot fill the disk or poison an entry.
- **Origin requests.** The origin certificate is verified by default. Redirects of the origin are passed on only below the origin itself; with `UPSTREAM_FOLLOW_REDIRECTS=true` a redirect to another host is followed only when every address of that host is public. Bodies are bounded by `UPSTREAM_MAX_SIZE` and streamed to disk.
- **Response headers.** Every asset is sent with the media type of its extension and `X-Content-Type-Options: nosniff`, whatever the origin claims. SVG responses carry `Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox`, so an SVG opened from the proxy runs no script.
- **Credentials.** Purge keys are scoped per origin; metrics keys are separate and cannot purge; secrets are compared in constant time; `.config.cache.php` is readable by its owner only, and the web server configurations deny it.
- **Trusted proxies.** `X-Forwarded-Proto` and `X-Forwarded-For` are believed from `TRUSTED_PROXIES` only, in PHP and in the web server configurations.
- **Bounded state.** The negative cache and the coalescing outcomes are at most 4096 files each, in `var/`, outside the document root.

What remains the operator's responsibility:

- **Random paths.** Any client can make the proxy fetch a path that exists on the origin, one fetch per distinct path. The circuit breaker bounds the load on a failing origin; `MISS_RATE_LIMIT`, or a rate limiter in front of the proxy, bounds it per client.
- **Session-dependent content.** Adding `html`, `htm`, `xml`, `json` or `php` to `CACHEABLE_EXTENSIONS` caches whatever the origin answers for those paths, without the client's cookies, and serves it to everyone. `check-env` warns about these extensions.
- **`SOURCE_ORIGIN_SERVER`** connects straight to an address and bypasses any CDN or WAF in front of the origin's DNS name.
- **Isolation.** Run PHP-FPM for the proxy in a pool of its own, as a user that no other application shares, and keep `var/` and the project root out of reach of other users.

See [SECURITY.md](SECURITY.md) for how to report a vulnerability.

## Known limitations

- **Legacy `X.webp` URLs go through PHP.** With `LEGACY_WEBP_URLS=true` (the default), the `X.webp` URLs that 2.x redirected clients to are answered with the WEBP variant of `X.jpg`, `X.png` and so on. The web server cannot make that mapping, so PHP answers these URLs on every request, hits included. Turn the key off once no client asks for them.
- **Apache frames PHP responses itself.** Since Apache 2.4.59 (CVE-2024-24795), `mod_proxy_fcgi` does not trust the `Content-Length` PHP sends: responses that PHP serves through Apache may be chunked, and a `HEAD` answered by PHP has no `Content-Length`. Hits that Apache serves itself are not affected.
- **HSTS on Apache** is sent only when the `HTTPS` variable is set, that is, on connections Apache terminates with TLS itself. Behind a TLS-terminating proxy, let that proxy send `Strict-Transport-Security`.
- **A legacy `X.webp` 404 outlives a purge of its raster.** When neither `X.webp` nor a raster sibling existed, the `404` is remembered for `X.webp` itself. A later `PURGE` of `/X.png` does not forget it: `/X.webp` keeps answering `404` until `NEGATIVE_CACHE_TTL` expires, or until `/X.webp` is purged. Likewise, a purge of `/a.png` forgets the `404` of `/a.png?v=2` only when some entry of the host was stored with that query string.
- **Signatures do not expire.** A resize signature has no expiry: a published resize URL stays valid until `RESIZE_KEY` changes. A purge HMAC can be replayed within its 300-second window; a purge is idempotent, so a replay only repeats the same removal.
- **Shared hosting of PHP-FPM.** The compiled configuration, the lock directories and OPcache preloading all assume that the proxy has a PHP-FPM pool and a user of its own. Running it in the pool of another application is not supported.
- **Web-server hits are invisible to `/metrics`**; count them from the access log.
- **Early Hints** need FrankenPHP; PHP-FPM and mod_php cannot send a `103`.

## Performance

Measured with the repository's own suite (`tests/Performance/run.sh`, the options of CI) on a 4-vCPU Xeon 2.1 GHz container that is not a reference machine:

| Path | Metric | Target | Apache 7.2 | nginx 7.2 | Apache 8.5 | nginx 8.5 |
| --- | --- | --- | --- | --- | --- | --- |
| Hit served by the web server | p50 / static file | at most 1.10 | 1.22 | 1.16 | 1.21 | 1.11 |
| | requests per second / static file | at least 0.90 | 0.82 | 0.86 | 0.81 | 0.86 |
| Hit served by PHP | server-side p50 | under 2 ms | 1.35 ms | 1.31 ms | 1.07 ms | 1.03 ms |
| Cold miss | p50 minus origin p50 | under 5 ms | 4.29 ms | 3.48 ms | 3.13 ms | 2.28 ms |
| Thundering herd (500 requests) | origin fetches | exactly 1 | 1 | 1 | 1 | 1 |

- Web-server hits miss the 10% target: 1.11 to 1.22 times a static file at p50, and 0.81 to 0.86 times its requests per second. In absolute terms, the best p50 at 1000 requests per second was 0.304 ms against 0.251 ms for the static file on Apache, and 0.187 ms against 0.169 ms on nginx (PHP 8.5).
- The cold-miss overhead varies by a few milliseconds between runs, and on Apache it missed the 5 ms target in 4 of 8 runs (5.3 to 7.1 ms).
- With `AllowOverride All` instead of including `public/.htaccess`, Apache hits measured 2.01 times a static file at p50: keep the `Include`.
- The default `METRICS_STORE=file` does not change the latency a client sees, but it costs PHP-served hits at saturation: on nginx the cost varied from about 15% to 30% fewer per second across four on/off runs (for example 2918 against 3713 with PHP 8.5), on Apache 2% to 7%. `METRICS_STORE=off` removes that cost, without `/metrics`.

The CI gate runs the same suite on shared runners with wide margins, so it catches large regressions (about +50% at p50), not a 10% one. [docs/performance.md](docs/performance.md) gives the method, the p99 figures, the gate and the caveats.

## Upgrading from 2.x

3.0 changes the cache layout, the configuration, the purge mechanism and several responses. In short: install the new dependencies, set `PROXY_HOSTS`, move the 2.x conversion keys to `IMAGE_CONVERTER` and `IMAGE_API_*`, replace the web server configuration, run `bin/proxy-cdn cache:migrate-2x`, and replace any `?purge=1` with an authenticated `PURGE`. [UPGRADING.md](UPGRADING.md) lists every breaking change.

## Testing and development

```bash
composer install                     # with the development dependencies
composer test                        # PHPUnit: unit, functional and integration suites
composer analyse                     # PHPStan at level max, as PHP 7.2.5 and as PHP 8.5
composer cs:check                    # php-cs-fixer, dry run
composer taint                       # Psalm taint analysis
composer ci                          # cs:check, analyse and test
```

The static analysis tools live in `tools/<name>/` with their own `composer.json`: install each with `composer install --working-dir=tools/<name>`. The Docker-based suites run outside PHPUnit:

- `tests/Integration/docker/run.sh --php 8.5`: Apache and nginx with PHP-FPM, against a stand-in origin.
- `tests/Integration/laravel/run.sh`: the kernel mounted in a fresh Laravel application.
- `tests/Contract/run.sh --api <checkout>`: the remote converter against the conversion API.
- `tests/Performance/run.sh --php 8.5`: the latency suite.

[CONTRIBUTING.md](CONTRIBUTING.md) describes the workflow and the conventions.

## Versioning and compatibility

Releases are published as git tags, numbered major.minor.patch. 3.0 is a major release with breaking changes from 2.x, described in [UPGRADING.md](UPGRADING.md); [CHANGELOG.md](CHANGELOG.md) lists the changes of each release.

CI runs the test suite on PHP 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5, each with the lowest and the highest dependencies allowed by `composer.json` (Symfony components 5.4 to 8.x), plus the Apache and nginx integration suite on PHP 7.2, 8.1 and 8.5, the Laravel 13 embedding check on PHP 8.4, the conversion API contract on PHP 7.2 and 8.5, and the latency suite on PHP 7.2 and 8.5.

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup, the checks CI runs and the commit conventions.

## Reporting a vulnerability

Do not open a public issue for a security problem. Report it privately through GitHub Security Advisories, as described in [SECURITY.md](SECURITY.md).

## License

BSD 3-Clause. Copyright (c) 2020, Maurizio Fonte. See [LICENSE](LICENSE).
