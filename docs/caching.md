# How caching works

This page describes what the proxy stores, where, and how it decides between the web server, PHP and the origin. It is a reference for operators; the [README](../README.md) gives the overview.

- [Request flow](#request-flow)
- [Cache layout](#cache-layout)
- [Cache tiers](#cache-tiers)
- [Origin cache semantics](#origin-cache-semantics)
- [Query strings](#query-strings)
- [Content negotiation](#content-negotiation)
- [Image variants and the pending tier](#image-variants-and-the-pending-tier)
- [Negative cache](#negative-cache)
- [Circuit breaker](#circuit-breaker)
- [Request coalescing](#request-coalescing)
- [How origin answers are passed on](#how-origin-answers-are-passed-on)
- [Files served by PHP](#files-served-by-php)
- [X-Cache-Status](#x-cache-status)

## Request flow

The web server decides first:

- A `GET` or `HEAD` whose cache file it can locate and that exists on disk is served by the web server alone, as the AVIF or WEBP variant or the Brotli or gzip encoding when the client accepts one. PHP is not involved.
- Everything else reaches `public/index.php`: a miss, a query string the web server cannot map, an entry of the managed tier, an image waiting for its variants, a legacy `X.webp` URL, `PURGE`, `OPTIONS`, `/healthz`, `/metrics`, and any host the web server has no directory for.

PHP then answers in this order:

1. `/healthz` and `/metrics` (see [Operations](operations.md#health-check)).
2. A `Host` header that is not a valid host name gets `400`; a host outside `PROXY_HOSTS` gets `421`. `OPTIONS` gets the CORS preflight answer. `PURGE` is authenticated and carried out. Any method other than `GET` and `HEAD` gets `405`; `X-HTTP-Method-Override` is ignored.
3. The path is decoded once and refused (`400`) when it could name another file; an extension outside `CACHEABLE_EXTENSIONS` gets `404`; the query string is reduced to the allowlisted parameters.
4. A signed resize URL is verified before anything else is done for it.
5. A remembered `404` or `410` is answered from the negative cache.
6. A stored entry is served: from the web-server tier, or from the managed tier while fresh, stale within its allowance, or after revalidation.
7. While the circuit breaker of the origin is open, a miss is answered `503` with `Retry-After`.
8. Otherwise one request fetches the entry while the others that miss it wait (request coalescing). The response is stored and served, or streamed without storing when the origin forbids storing it.
9. After the response: background revalidations, image conversions, the purge fan-out to cluster peers and the metrics update.

## Cache layout

Entries live below `public/_cache/`, one directory per host of `PROXY_HOSTS`. With `D` the bucket directory of an entry and `P` its decoded path:

```text
public/_cache/
├── .work/                         private work directories of the image conversions
└── <host>/                        D for a URL without query string
    ├── P                          the entry, as the origin sent it
    ├── .avif/P.avif               its AVIF variant (raster images)
    ├── .webp/P.webp               its WEBP variant (raster images)
    ├── .br/P                      its Brotli encoding (text, SVG, icon and font types)
    ├── .gz/P                      its gzip encoding
    ├── .pending/P                 a new raster entry waiting for its variants
    ├── .pending/.avif/P.avif      its AVIF variant, staged
    ├── .pending/.webp/P.webp      its WEBP variant, staged
    ├── .q/<query>/...             D for a query string the web server can map
    ├── .qh/<hash>/...             D for any other query string (PHP only)
    └── .m/                        the managed tier, same layout
        └── .meta/P.json           the metadata of a managed entry
```

Every sibling lives in a directory whose name starts with a dot. The path sanitizer refuses any request path segment that starts with a dot, so no URL can name a sibling, a pending file or the managed tier, and both web server configurations answer `403` to such paths. Direct requests for `/_cache/...` and for the 2.x bucket `/_qs/...` are refused too.

The proxy state lives in `var/` of the project, outside the document root:

| Directory | Contents |
| --- | --- |
| `var/negative/` | The negative cache: at most 4096 slot files. |
| `var/coalescing/` | The outcomes that request coalescing shares: at most 4096 slot files. |
| `var/locks/` | The lock files of request coalescing, of the conversions and of the API discovery. |
| `var/breaker/` | One circuit breaker state file per origin. |
| `var/ratelimit/` | The buckets of `MISS_RATE_LIMIT`. |
| `var/metrics/` | The counters of `/metrics` (`METRICS_STORE=file`). |
| `var/converter-cache/` | What the conversion library detected (isolation level, backend capabilities); needs ext-posix. |
| `var/convert/` | The private directories of the conversion processes. |
| `var/conversion-api/` | The capabilities of the conversion API and its circuit breaker. |
| `var/conversion-failures/` | The markers of failed conversions. |
| `var/cache-usage.json` | The size measured by the last `cache:evict`. |

None of these needs cleaning: their number of files is bounded by design. The lock, metrics and rate-limit directories must not be writable by other users; the conversion library refuses them otherwise, and `check-env` reports it.

## Cache tiers

The proxy reads the origin's response headers as a shared cache does (RFC 9111) and stores each entry in one of two tiers:

- **The web-server tier**, `public/_cache/<host>/`: entries whose lifetime is unknown or at least `CACHE_REVALIDATE_BELOW` (one hour by default). Apache or nginx serve them without PHP, and they are kept until they are purged or evicted, as in 2.x. The long-lived assets of a site never touch PHP again.
- **The managed tier**, `public/_cache/<host>/.m/`: entries with a shorter lifetime. Only PHP serves them, with the `max-age` they have left, and PHP revalidates them with the origin once they expire.

`CACHE_REVALIDATE_BELOW=0` puts every entry in the web-server tier.

## Origin cache semantics

- **No storing.** A response with `no-store` or `private` is never stored. It is streamed to the client with `X-Cache-Status: BYPASS`, and every request fetches it again.
- **Lifetime.** `s-maxage`, else `max-age`, else `Expires` minus `Date`, minus `Age`. `no-cache` without field names makes it 0: stored, but revalidated before each use.
- **Revalidation.** An expired managed entry is revalidated with `If-None-Match` and `If-Modified-Since`, from the `ETag` and `Last-Modified` the origin sent. A `304` renews it (`REVALIDATED`), a `200` replaces it, a `404` or `410` removes it.
- **Stale while revalidate.** Within the origin's `stale-while-revalidate`, else `STALE_WHILE_REVALIDATE`, the expired entry is served at once (`STALE`) and revalidated after the response.
- **Stale if error.** While the origin is unavailable (5xx, 429, timeout, open circuit breaker), the expired entry keeps being served (`STALE`) for at most the origin's `stale-if-error`, else `STALE_IF_ERROR`.
- **Forbidden stale use.** `must-revalidate`, `proxy-revalidate`, `s-maxage` (which implies `proxy-revalidate`) and `no-cache` forbid any stale use.

Every stored asset the proxy serves carries `Cache-Control: public, max-age=<seconds>, must-revalidate`, where `<seconds>` is `CACHE_EXPIRY`, or less for a managed entry. `must-revalidate` rather than `immutable` keeps purges effective in browsers.

## Query strings

Only the parameters listed in `QUERY_STRING_ALLOWLIST` (`v,ver,version,id` by default) key the cache and reach the origin. Add the parameter your build tool uses if it is another one; otherwise a new build is served from the entry of the old one. The other parameters, such as `utm_source` or a random `?_=<timestamp>`, are dropped (`QUERY_STRING_MODE=ignore`) or refused with `400` (`reject`).

The kept parameters, sorted by name (repeated names keep their order, percent-escapes in upper case), form the canonical query string that keys the entry:

- No kept parameter: the entry of the path, `public/_cache/<host>/<path>`.
- A canonical query string of letters, digits and `-._~=&` only, at most 200 characters, not starting with a dot: `public/_cache/<host>/.q/<query>/<path>`. The web server finds it by itself when the client sent the canonical form.
- Any other canonical query string: `public/_cache/<host>/.qh/<hash>/<path>`, where `<hash>` is the first 16 hex digits of its SHA-256. Only PHP finds it.

```text
/hero.jpg?v=42               -> _cache/<host>/.q/v=42/hero.jpg          web server hit
/hero.jpg?ver=2&utm_x=1&v=1  -> _cache/<host>/.q/v=1&ver=2/hero.jpg     PHP hit (not the canonical form)
/hero.jpg?v=1&ver=2          -> the same file                           web server hit
/hero.jpg?v=a%2Fb            -> _cache/<host>/.qh/<hash>/hero.jpg       PHP hit
```

The origin receives the kept parameters as the client wrote them, in their order. No other normalization is made, so `a+b` and `a%20b` are different entries.

## Content negotiation

A raster entry (`jpg`, `jpeg`, `png`, `gif`, `bmp`) is always served at its own URL; 2.x redirected clients to `X.webp` instead. The web server and PHP choose the same file from the `Accept` header:

1. The AVIF variant, when `image/avif` is listed by name without `q=0` and the variant exists.
2. Otherwise the WEBP variant, when `image/webp` is listed the same way and the variant exists.
3. Otherwise the original.

A bare `*/*` does not count as support for either format. Every raster response carries `Vary: Accept`.

Text, SVG, icon and font entries are negotiated the same way on `Accept-Encoding`: the Brotli encoding to clients that list `br`, the gzip one to those that list `gzip`, with `Vary: Accept-Encoding`. The encodings are written at store time when `PRECOMPRESS` is on.

## Image variants and the pending tier

With `IMAGE_CONVERTER` set to `local` or `remote`, each new raster entry gets the variants of `IMAGE_FORMATS`:

1. The request that stores the image answers with the original as soon as it is stored, with `Vary: Accept` and `Cache-Control: public, max-age=0, must-revalidate`, so that neither the browser nor a shared cache keeps the original in place of a variant.
2. The image is stored in the pending tier, `<bucket>/.pending/<path>`, where the web server does not look. PHP answers its requests, the same way, until every variant is settled.
3. The variants are made after the response, by the PHP worker that answered, and staged next to the pending copy (`.pending/.avif/`, `.pending/.webp/`).
4. Once every variant is settled (made, or failed for good: the converter refused the image, the variant would not be smaller, the format is not supported), the staged variants and then the image are published with one `rename()` each. From then on the web server serves them with the usual `Cache-Control`.

A failure because the converter cannot convert now (unreachable, `503`, rate limited, breaker open, capabilities not discovered yet) settles nothing: the next request for the image, which PHP answers, tries again, at most `NETWORK_ERROR_TTL` seconds later. An image still pending after one hour is published without the variants still missing, so that no image stays away from the web server for good; `PURGE` it to try them again. A variant that failed because of the image is not tried again for a day; a format the converter does not support is asked again after 300 seconds.

The conversions run after the response: under PHP-FPM (`fastcgi_finish_request()`) the client is already gone; under a server API without it (mod_php, the built-in server) the response is flushed but the connection stays open until they end. Each conversion is bounded by `IMAGE_CONVERSION_TIMEOUT`, and no conversion starts once one `IMAGE_CONVERSION_TIMEOUT` per format has passed since the response (110 seconds with the defaults). The conversions of one image run in one request at a time, under a lock of `var/locks/`. A `request_terminate_timeout` of the PHP-FPM pool, counted from the start of the request, also bounds them: set it above that budget plus the coalescing timeout.

Every output is verified (format, dimensions, frame count) before it is moved into the cache, and a conversion that fails never fails the request.

## Negative cache

A `404` or `410` of the origin is remembered in `var/negative/` for `NEGATIVE_CACHE_TTL` seconds and answered without asking the origin again. The markers live in one flat directory of at most 4096 slot files, named after the first three hex digits of the SHA-256 of the entry, so a flood of random paths creates no file below `public/`. Two entries that share a slot replace each other's marker, which costs one more origin request.

A successful fetch forgets the marker of its entry, and so does a purge of the entry.

## Circuit breaker

Each origin has one circuit breaker, kept in `var/breaker/` and updated under a lock, so that every PHP worker shares it:

- **Closed.** Each unavailability failure (5xx, 429, timeout, DNS, connection, TLS or network error) is counted. A success, or a pause longer than `NETWORK_ERROR_TTL` between failures, starts the count again.
- **Open.** After `CIRCUIT_BREAKER_THRESHOLD` consecutive failures, every request that would need a fetch from that origin is answered `503` with `Retry-After` for `NETWORK_ERROR_TTL` seconds. Stored entries are still served.
- **Half-open.** Then one request is let through as a probe. Any HTTP answer that is not unavailability (a success, but also a `403`, `404`, `410` or redirect) closes the breaker; unavailability opens it again at once.

When the state cannot be read or written, the breaker stays closed. Because the requests of a failing URL share each failure for the coalescing window (below), one URL counts one failure per window: with the defaults, a single failing URL opens the breaker after about 20 seconds of traffic, and several failing URLs sooner.

## Request coalescing

Of the requests that miss the same entry at the same time, one (the leader) fetches it from the origin while the others wait for it and then serve what it stored. **A burst of requests on a cold entry costs one origin fetch**; the performance suite checks this with 500 concurrent requests on every run.

The guarantee holds when the leader stores nothing, too:

- When the origin fails, or answers `403`, `404` or `410`, the waiters answer with the same error instead of fetching in turn. When the origin redirects, they pass on the same redirect.
- That outcome is remembered for 5 seconds (`NETWORK_ERROR_TTL` when shorter), for every request of the URL, including one that arrives after the leader has finished. The origin sees at most one fetch of a failing or redirecting URL per window, and a URL the origin failed may be answered with that error for up to 5 seconds after the origin recovers.
- When the origin forbids storing the response (`no-store`, `private`), the requests of that URL fetch in parallel for the window: each needs its own response.
- A purge, or a stored response, forgets the outcome.

A waiter gives up after the coalescing timeout and answers `503`. The timeout is `UPSTREAM_TIMEOUT` plus 30 seconds (60 seconds with the defaults), plus, when resize URLs and a converter are configured, the time a resize may take before the answer: one `IMAGE_CONVERSION_TIMEOUT`, and for `remote` twice the connect timeout and 30 seconds of discovery, and one more `IMAGE_CONVERSION_TIMEOUT` with `IMAGE_API_FALLBACK` (210 seconds with the defaults). The web server must give PHP longer than that to answer: the configurations in `config/` set 300 seconds (nginx `fastcgi_read_timeout`, Apache `ProxyTimeout`; both default to 60 seconds).

The lock is a file of `var/locks/`, which must not be writable by other users. If the lock directory cannot be used safely, the proxy logs a warning and every request fetches by itself.

## How origin answers are passed on

| Origin answer | Answer to the client | Remembered |
| --- | --- | --- |
| `200` | the asset, `MISS` | stored (unless `no-store` or `private`) |
| `404`, `410` | `404`, `410` | negative cache, `NEGATIVE_CACHE_TTL` |
| `403` | `403` | coalescing window only |
| `3xx` to a URL below its own origin | the same status, `Location` rewritten to the path on the proxy, not cached | coalescing window only |
| `3xx` elsewhere, or without a usable `Location` | `502` | coalescing window only |
| `5xx`, `429`, timeout, DNS, connection, TLS or network error | `503` with `Retry-After` | counts toward the circuit breaker |
| another `4xx`, or a body over `UPSTREAM_MAX_SIZE` | `502` | coalescing window only |

With `UPSTREAM_FOLLOW_REDIRECTS=true` the proxy follows the redirects itself, under the rules of that key, and only the last answer counts.

Error responses are plain text, with `Cache-Control: no-store`, an error code and a Ray ID:

| Status | Error code |
| --- | --- |
| `400` | `Cannot serve this request` (refused path), `Query parameter not allowed`, `Invalid Host header` |
| `401` | `Purge requires a purge key` (with `WWW-Authenticate: Bearer realm="proxy-cdn purge"`) |
| `403` | `Remote origin refused the file`, `Resize URL refused` |
| `404` | `Request cannot be processed` (extension not cacheable), `Cannot read remote origin file` |
| `405` | `Method not allowed` (with `Allow: GET, HEAD, OPTIONS`) |
| `410` | `Remote origin file is gone` |
| `421` | `Unknown host` |
| `429` | sent by the miss rate limit, with `Retry-After` |
| `500` | `Cannot create cache directory`, `Cannot create cache file`, unexpected errors |
| `502` | `Bad response from the remote origin` |
| `503` | `Upstream temporarily unavailable` (with `Retry-After`), `ENV_FILE_LOAD_ERR` (invalid configuration) |

## Files served by PHP

An origin body goes to a temporary file and then to the cache, in chunks: it is never held in PHP memory. A file PHP serves is streamed too, with `ETag` and `Last-Modified`, a `304` to `If-None-Match` and `If-Modified-Since`, and a `206` to a single `Range` (with `If-Range`). PHP opens the file when it answers and takes `Content-Length`, `ETag` and `Last-Modified` from the open file, so a purge or a revalidation that replaces the file meanwhile does not change the response.

With `SENDFILE` set and the web server configured for it, PHP hands the file to the web server instead (see [Sending files](deployment.md#sending-files-from-the-web-server)).

## X-Cache-Status

| Value | Meaning |
| --- | --- |
| `HIT` | Served from the cache, by the web server or by PHP. |
| `MISS` | Fetched from the origin and stored. |
| `REVALIDATED` | An expired managed entry that the origin confirmed with a `304`. |
| `STALE` | An expired managed entry served while it is revalidated, or while the origin fails. |
| `BYPASS` | A response the origin forbids storing, streamed to the client. |
| absent | An error response. |
