# Operations

This page covers the console commands, the health and metrics endpoints, logging, the miss rate limit, Early Hints and troubleshooting.

- [Console commands](#console-commands)
- [Scheduled maintenance](#scheduled-maintenance)
- [Health check](#health-check)
- [Metrics](#metrics)
- [Counting the hits of the web server](#counting-the-hits-of-the-web-server)
- [Logging](#logging)
- [Request ID](#request-id)
- [Miss rate limit](#miss-rate-limit)
- [Early Hints](#early-hints)
- [Troubleshooting](#troubleshooting)

## Console commands

`bin/proxy-cdn` is a Symfony Console application. Run it from the project root as the PHP-FPM pool user, so that the files it writes keep the owner the proxy expects.

### check-env

```bash
php bin/proxy-cdn check-env
composer check-env                # the same
```

Validates the configuration with the rules the proxy applies at startup, then checks the host:

- error: the curl extension is not loaded;
- warnings for `IMAGE_CONVERTER=local` (or `IMAGE_API_FALLBACK=true`): no conversion backend (`gd` or `imagick`, or `cwebp`, `avifenc` or `vips` in `PATH`), or no ext-posix;
- warnings: a deprecated 2.x key still set; `CACHE_EXPIRY` different from the `max-age` in `public/.htaccess` or `config/nginx/proxy-cdn-headers.conf`; a state directory the conversion library refuses (`var/locks`, `var/metrics`, `var/ratelimit`); a log file that cannot be written; what 2.x left in the installation (`.cached.env.php`, entries in `public/`).

It exits with `1` on any error. With `--informational`, as the Composer `post-install-cmd` and `post-update-cmd` hooks run it, it always exits with `0`, so that installing the dependencies never fails because the proxy is not configured yet.

### cache:purge

```bash
php bin/proxy-cdn cache:purge https://cdn.example.com/images/hero.jpg 'https://cdn.example.com/app.css?v=42'
php bin/proxy-cdn cache:purge --local https://cdn.example.com/images/hero.jpg
php bin/proxy-cdn cache:purge --all --host=cdn.example.com
```

Purges URLs of a proxy host on this instance as an authenticated `PURGE` would (see [Purge](../README.md#purge)), then on every peer of `CLUSTER_PEERS` unless `--local` is given. An origin URL is mapped to its proxy host. `--all --host=<host>` removes every entry of one host on this instance only; run it on each instance. Exit codes: `1` when a URL is refused, `2` when a peer did not confirm the purge.

### cache:warmup

```bash
php bin/proxy-cdn cache:warmup https://origin.example.com/sitemap.xml
php bin/proxy-cdn cache:warmup urls.txt --concurrency=8 --via=127.0.0.1:8080 --scheme=http
php bin/proxy-cdn cache:warmup sitemap.xml --accept='image/avif,*/*' --accept='image/webp,*/*'
```

Requests the `<loc>` and `<image:loc>` entries of a sitemap (a local file, or a URL on a proxy host or an origin; a sitemap index is followed one level deep) or the URLs of a text file, one per line, through the proxy. The requests go to the proxy itself, never to the origin, so they follow the rules of live traffic: host allowlist, path and query-string rules, redirect rules, circuit breaker and miss rate limit. Origin URLs are mapped to their proxy host; URLs of other hosts, refused paths, extensions that are not cacheable and refused query strings are skipped and listed.

| Option | Default | Meaning |
| --- | --- | --- |
| `--concurrency`, `-c` | `4` | Requests at a time, 1 to 32 (in turn without ext-curl). |
| `--via` | the address of the host name | Send the requests to this `address[:port]` of one instance. |
| `--scheme` | `https` | `https` or `http`. |
| `--accept` | `image/avif,image/webp,*/*` | The `Accept` header; repeat it to warm each variant. |
| `--limit` | `50000` | The most URLs read. |

It exits with `1` when a request does not get a 2xx answer.

### cache:evict

```bash
php bin/proxy-cdn cache:evict                    # down to CACHE_MAX_SIZE
php bin/proxy-cdn cache:evict --max-size=2G --dry-run
php bin/proxy-cdn cache:evict --by=mtime         # for a file system mounted noatime
```

Brings `public/_cache` down to `CACHE_MAX_SIZE` (or `--max-size`), least recently used first. `--by=atime` (the default) dates an entry by the last read of the entry or of any of its variants and encodings; `--by=mtime` uses the last write, for a file system mounted `noatime`. An entry always goes with its variants, encodings and metadata, siblings first, and a pending image with its staged variants. Symbolic links are never followed or removed. The measured size is written to `var/cache-usage.json`, which `/metrics` reports; without a cap the command only measures. `--dry-run` lists what it would remove.

### cache:migrate-2x

```bash
php bin/proxy-cdn cache:migrate-2x                 # print the plan, change nothing
php bin/proxy-cdn cache:migrate-2x --force         # move the 2.x cache
php bin/proxy-cdn cache:migrate-2x --wipe --force  # delete it instead
```

Moves the cache that 2.x left in `public/` to `public/_cache/<host>/`, for the host of `SOURCE_ORIGIN` (or `--host`). See [UPGRADING.md](../UPGRADING.md#move-or-delete-the-2x-cache) for what it moves, deletes and leaves.

## Scheduled maintenance

The state in `var/` is bounded and needs no cleaning. The cache grows until it is purged or evicted:

```cron
# Keep the cache under CACHE_MAX_SIZE, and the size metric fresh.
*/30 * * * * www-data cd /srv/proxy-cdn && php bin/proxy-cdn cache:evict --quiet
```

Rotate `logs/proxy.log` with a standard tool:

```text
# /etc/logrotate.d/proxy-cdn
/srv/proxy-cdn/logs/proxy.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
}
```

## Health check

`GET /healthz`, on any host, answers:

- `200` with `{"status":"ok"}`;
- `503` with `{"status":"unavailable","checks":{"cache":"unwritable","state":"ok"}}` when `public/_cache` or `var/` cannot be written (or created).

It names no path, version or setting, and never contacts the origin: an origin outage is the circuit breaker's job, and taking every instance out of the load balancer for it would stop the hits too. The states of image conversion (pending images, failed conversions, an open breaker of the conversion API) do not fail it either: they leave the original served, and `/metrics` reports them. Methods other than `GET` and `HEAD` get `405`.

## Metrics

`GET /metrics` is served only when `METRICS_KEYS` is set, to requests with `Authorization: Bearer <key>` (`401` with `WWW-Authenticate: Bearer realm="proxy-cdn metrics"` otherwise), in the Prometheus text format 0.0.4. The metrics keys cannot purge.

| Metric | Type | Labels |
| --- | --- | --- |
| `proxy_cdn_up` | gauge | |
| `proxy_cdn_metrics_available` | gauge | |
| `proxy_cdn_requests_total` | counter | `method`, `cache`, `status` |
| `proxy_cdn_request_duration_seconds` | histogram | `cache` |
| `proxy_cdn_origin_fetches_total` | counter | `outcome` |
| `proxy_cdn_purges_total` | counter | `result` |
| `proxy_cdn_peer_purges_total` | counter | `result` |
| `proxy_cdn_miss_rate_limited_total` | counter | |
| `proxy_cdn_miss_rate_limit_unavailable_total` | counter | |
| `proxy_cdn_early_hints_total` | counter | |
| `proxy_cdn_image_conversions_total` | counter | `converter`, `result` |
| `proxy_cdn_cache_bytes` | gauge | |
| `proxy_cdn_cache_entries` | gauge | |
| `proxy_cdn_cache_pending_entries` | gauge | |
| `proxy_cdn_cache_scan_timestamp_seconds` | gauge | |

- `method` is `GET`, `HEAD`, `OPTIONS`, `PURGE` or `other`; `cache` the `X-Cache-Status` of the response or `none`; `status` the HTTP status.
- `outcome` is `success`, `not_modified` or the failure reason of the fetch (`not_found`, `gone`, `forbidden`, `redirect`, `client_error`, `server_error`, `too_large`, `timeout`, `dns`, `connect`, `tls`, `network`, `other`).
- `result` is `purged` or `refused` for purges, `ok` or `failed` for peer purges. For image conversions it is `ok`, `rejected` (the image cannot be converted as asked), `unavailable` (the converter cannot convert now) or `circuit_open` (refused at once while the breaker of the conversion API is open); `converter` is `local` or `remote`.
- Every label value comes from a fixed set: no host, path, client, peer or version is a label, so the series cannot grow with the traffic.
- The cache gauges come from the last `cache:evict` run, and are missing until one has run.
- `proxy_cdn_metrics_available` is `0` when the store cannot be read: the counters are then missing from the scrape, not reset.

The counters live in `var/metrics/`, spread over eight shards that are summed on read (each a lock file and two checksummed slots, so that a write cut by a full disk or a killed worker never lowers them), and are updated once per request after the response. Only the requests that reach PHP are counted. `METRICS_STORE=off` counts nothing, saves the cost measured in [Performance](performance.md#metrics-store-cost), and leaves only the gauges.

## Counting the hits of the web server

Most hits never reach PHP, so `/metrics` cannot count them. Log the `X-Cache-Status` of every response and count the log lines (`cache=HIT`, `cache=MISS`, `cache=-` for an error). Both configurations hold the format, commented out:

```nginx
# config/nginx/proxy-cdn.conf: log_format in the http context, access_log in the server block
log_format proxycdn '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" "$http_user_agent" '
                    'cache=$sent_http_x_cache_status rt=$request_time';
access_log /var/log/nginx/proxy-cdn.access.log proxycdn;
```

```apache
# config/apache/proxy-cdn.conf, in the VirtualHost
LogFormat "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\" cache=%{X-Cache-Status}o %D" proxycdn
CustomLog ${APACHE_LOG_DIR}/proxy-cdn.access.log proxycdn
```

nginx `stub_status` and Apache `mod_status` report totals only, never hits apart from misses.

## Logging

The proxy writes one JSON object per line (JSON Lines) to `logs/proxy.log`, or to `LOG_FILE` (an absolute path, `stderr` or `error_log`). A new log file is created with mode `0640` and its directory with `0755`. The fields always come in this order:

```json
{"timestamp":"2026-03-14T12:34:56.123456+00:00","level":"error","channel":"proxy-cdn","request_id":"4bf92f3577b34da6a3ce929d0e0e4736","message":"upstream fetch failed","context":{"url":"https://origin.example.com/hero.jpg","reason":"timeout","transport_error_code":28,"transport_error":"Operation timed out after 30000 ms","response_code":0}}
```

- `timestamp`: RFC 3339 with microseconds; `level`: the PSR-3 level; `channel`: `proxy-cdn`; `request_id`: see below.
- `LOG_LEVEL` sets the least severe level written: `notice` by default, `debug` with `DEBUG=true`.
- `error`: origin fetch failures (`upstream fetch failed`, with `reason`, `response_code` and, for a transport failure, the libcurl error number and message), an invalid configuration, a failed purge fan-out.
- `warning`: refused purges (`purge refused`, with the client address read through `TRUSTED_PROXIES`), failed image conversions (`image conversion failed, the original is served`, with `conversion_error_code` for a code of the conversion library and `error_code` for the other codes), failed peer purges, coalescing timeouts, stale entries served while the origin is unavailable.
- `notice`: accepted purges (`purge`, with the host, path, number of removed files, scheme and client address), misses refused by the rate limit, images published without all their variants.
- `info`: origin `404`s, written only when `LOG_LEVEL` allows it.

An exception in the context is written as an object (`class`, `message`, `code`, `file`, `previous`). The signatures of resize URLs are masked in log lines.

## Request ID

Every response PHP sends carries `X-Request-Id`, and every log line of the request carries the same value as `request_id`. A client value of 1 to 128 characters among letters, digits and `._:/+=@-` is adopted; otherwise 32 new hex digits are generated. The ID is a correlation token, not a credential, so it is adopted from any client. It is sent to the conversion API with every conversion and resize of the request, including those made after the response, so that the API's log lines carry it too. The log lines of one console command run share one ID.

With `SENDFILE=x-accel-redirect`, nginx passes the `X-Request-Id` of PHP on (`proxy-cdn-locations.conf`). The hits the web server serves alone carry no request ID.

## Miss rate limit

`MISS_RATE_LIMIT` caps the origin fetches one client may cause per minute, with `MISS_RATE_LIMIT_BURST` at once after a pause. Above it, the answer is `429` with `Retry-After`.

- Hits are never limited, nor the requests that wait for another request's fetch of the same entry and share its answer. Every request that fetches counts: a miss, a signed resize URL, and each request of a coalescing window that is not coalesced (a response the origin forbids storing, a resize the converter could not make).
- The client is its address as `TRUSTED_PROXIES` reads it. An IPv6 client is its `/64` network; an IPv4-mapped or NAT64 address counts as its IPv4 address. Behind a load balancer, list it in `TRUSTED_PROXIES`, or every client shares its limit; `check-env` notes it.
- The buckets live in `var/ratelimit/`, keyed by a hash of the address. When they cannot be updated (a refused directory, an I/O error), the miss is let through, a warning is logged and `proxy_cdn_miss_rate_limit_unavailable_total` counts it: the limit protects the origin, it never decides who may read a public asset.

## Early Hints

`EARLY_HINTS` is a `Link` value, such as `</app.css>; rel=preload; as=style`, sent as `103 Early Hints` before a miss or a resize is fetched, for `GET` only. Only FrankenPHP lets PHP send an interim response; under PHP-FPM and mod_php nothing is sent, and `check-env` notes it. Browsers act on `103` for navigations only, so the hints matter for HTML entries, not for images and scripts. The value is sent as configured: it never names a cache path, a pending entry or a variant.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Every request answers `503` with `ENV_FILE_LOAD_ERR` | The configuration is invalid: run `php bin/proxy-cdn check-env`, or read `invalid configuration` in `logs/proxy.log`. |
| `check-env` warns that `IMAGE_CONVERSION_MODE is deprecated` | A 2.x key is still set and is ignored: see [Keys removed in 3.0](configuration.md#keys-removed-in-30). |
| `421 Unknown host` | The `Host` header is not in `PROXY_HOSTS`. |
| `404` for an asset that exists on the origin | The negative cache still remembers a `404`: wait `NEGATIVE_CACHE_TTL` seconds, or `PURGE` the URL. Or the extension is not in `CACHEABLE_EXTENSIONS`. |
| `503` with `Retry-After` for every miss of an origin | Its circuit breaker is open: wait `NETWORK_ERROR_TTL` seconds for the probe, or delete `var/breaker/` once the origin is fixed. |
| `503` after the upgrade, on an https origin | `UPSTREAM_VERIFY_TLS` is `true` by default since 3.0, and the origin certificate is self-signed or does not match. |
| Images never served as AVIF or WEBP | The conversions fail: look for `image conversion failed, the original is served` in the log, with its `error_code` or `conversion_error_code`. A variant that failed because of the image is tried again after a day, a format the converter does not support after 300 seconds, and either after a `PURGE`. |
| A new build is served from the old entry | The build tool's version parameter is not in `QUERY_STRING_ALLOWLIST`, so it is dropped from the cache key. |
| Redirect loop behind a load balancer | The TLS-terminating proxy is not trusted by the web server (see [Trusted proxies](deployment.md#trusted-proxies-and-the-https-redirect)), so its `X-Forwarded-Proto: https` is ignored. |
| No HTTPS redirect at all | `mod_rewrite` is off, or `public/.htaccess` is not included in the virtual host. |
| `check-env` warns about `var/locks` | The directory, or one of its parents, is writable by other users or owned by another user: request coalescing is off until it is fixed. |
| `logs/proxy.log` is not written | The PHP user cannot write `logs/`: fix the owner, or set `LOG_FILE`. |
