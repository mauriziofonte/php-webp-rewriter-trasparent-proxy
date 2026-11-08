# Deployment

This page covers the web server, PHP-FPM and filesystem setup of a production installation, the multi-instance example and embedding the proxy in another application. The configuration files referenced here live in [`config/`](../config/) and [`public/.htaccess`](../public/.htaccess).

- [Filesystem layout and permissions](#filesystem-layout-and-permissions)
- [Apache](#apache)
- [nginx](#nginx)
- [PHP-FPM and OPcache](#php-fpm-and-opcache)
- [Trusted proxies and the HTTPS redirect](#trusted-proxies-and-the-https-redirect)
- [Sending files from the web server](#sending-files-from-the-web-server)
- [Response headers](#response-headers)
- [Several instances behind a load balancer](#several-instances-behind-a-load-balancer)
- [Embedding the kernel in another application](#embedding-the-kernel-in-another-application)

## Filesystem layout and permissions

The document root must be `public/` of the project. Nothing else of the project is reachable over HTTP: neither web server configuration refuses names such as `/vendor/` or `/src/`, because an origin may serve `/vendor/livewire/livewire.js`, and they are proxied like any other path.

The PHP user (the PHP-FPM pool user) must be able to write:

| Path | Why |
| --- | --- |
| `public/_cache/` | The cache entries, their variants and encodings, the pending tier and the conversion work directory. |
| `var/` | The proxy state: negative cache, locks, circuit breakers, rate-limit buckets, metrics, conversion state. |
| `logs/` | `logs/proxy.log`, unless `LOG_FILE` points elsewhere. |
| the project root | `.config.cache.php`, the compiled configuration. Optional: without it the configuration is read on every request. |

`var/locks/`, `var/metrics/` and `var/ratelimit/` must not be writable by other users, and their parent directories neither: the conversion library's lock directory refuses them otherwise, and request coalescing, metrics or the rate limit are then off. `bin/proxy-cdn check-env` reports these cases.

Run PHP-FPM for the proxy in a pool of its own, as a user that no other application shares. The compiled configuration is readable by its owner only, the state directories are checked for ownership, and OPcache preloading applies to every pool of a PHP-FPM master (see below).

## Apache

Apache 2.4 with `mod_rewrite`, `mod_headers`, `mod_setenvif`, `mod_env`, `mod_mime`, `mod_ssl` and `mod_proxy_fcgi` (or mod_php). `mod_deflate` is optional, and `mod_xsendfile` (a third-party module) is needed only for `SENDFILE=x-sendfile`.

```bash
sudo a2enmod rewrite headers setenvif env mime ssl proxy_fcgi
```

Start from [`config/apache/proxy-cdn.conf`](../config/apache/proxy-cdn.conf) and adjust the values marked `# adjust`. It defines two virtual hosts:

- **`*:443`** serves the proxy over TLS. It includes `public/.htaccess` once, at startup, with `AllowOverride None`; with `AllowOverride All` instead, Apache parses the file on every request, which about doubles the cost of a hit (see [Performance](performance.md)). `UseCanonicalName Off` (the default) keeps the cache directory named after the `Host` header. `ProxyTimeout 300` gives PHP longer than the coalescing timeout to answer.
- **`*:80`** includes the same rules, which redirect plain HTTP to https. A load balancer that terminates TLS usually forwards to this port: list it there for `X-Forwarded-Proto` as well (see [Trusted proxies](#trusted-proxies-and-the-https-redirect)). If a TLS-terminating proxy forwards to `:80` (`PROXYCDN_HTTPS` set there), give this host the same `FilesMatch` handler and `ProxyTimeout 300` as `:443`, unless a server-wide PHP handler applies.

`public/.htaccess` holds the routing and the headers. It serves a hit when the cache file exists for the request's host, query string and negotiated variant or encoding, hands everything else to `index.php`, and denies:

- the cache read directly (`/_cache/`) and the 2.x query-string buckets (`/_qs/`);
- any path segment that starts with a dot;
- the project metadata (`.ht*`, `.git*`, `.env*`, `composer.json`, `composer.lock`, `README.md`, `LICENSE`), the compiled configuration (`.config.cache.php`) and the 2.x one (`.cached.env.php`), the temporary files of atomic writes (`.mftpcdn.*`), and the 2.x negative-cache markers (`*.404`, `*.netfail`).

It also forces `display_errors` off for PHP-FPM and mod_php, so that a PHP warning raised before the proxy runs (a body above `post_max_size`) never becomes a response body.

Where the virtual host cannot be changed, as on shared hosting, `AllowOverride All` without the `Include` works the same, more slowly.

Apache 2.4.59 and later frame the responses of `mod_proxy_fcgi` themselves (CVE-2024-24795), and `public/.htaccess` keeps it that way. The responses PHP sends through Apache may therefore be chunked, and a `HEAD` answered by PHP has no `Content-Length`. The hits Apache serves itself are not affected.

## nginx

Copy [`config/nginx/`](../config/nginx/) of the project to `/etc/nginx/proxy-cdn/`, then include [`proxy-cdn.conf`](../config/nginx/proxy-cdn.conf) in the `http` context (for example as `/etc/nginx/conf.d/proxy-cdn.conf`) and adjust the PHP-FPM address, the document root and the TLS certificate:

| File | Context | Contents |
| --- | --- | --- |
| `proxy-cdn.conf` | `http` | The maps include, the `geo` block of trusted proxies, the PHP-FPM upstream and one server block on `:80` and `:443`. |
| `proxy-cdn-maps.conf` | `http` | The HTTPS redirect decision, the query-string bucket and the negotiation of variants and encodings. |
| `proxy-cdn-locations.conf` | `server` | The routing: hits from `try_files`, the internal `/_cache/` location for `X-Accel-Redirect`, `403` for dot paths and `/_qs/`, and the hand-off to PHP. |
| `proxy-cdn-headers.conf`, `proxy-cdn-asset-headers.conf` | `location` | The headers of a hit, the same that PHP sends. |
| `proxy-cdn-types.conf` | `location` | The media types PHP uses. |
| `proxy-cdn-fastcgi.conf` | `location` | The FastCGI parameters: `PROXYCDN_SENDFILE`, `display_errors` off, `fastcgi_read_timeout 300s`. |

Set `SENDFILE=x-accel-redirect`, so that the files PHP serves are sent by nginx too. The server block uses `http2 on`, which needs nginx 1.25.1 or later.

`server_name _` serves every host; the proxy refuses the hosts outside `PROXY_HOSTS` itself, with `421`.

## PHP-FPM and OPcache

[`config/php/proxy-cdn.ini`](../config/php/proxy-cdn.ini) holds the php.ini lines of the pool, for example as `/etc/php/8.4/fpm/conf.d/99-proxy-cdn.ini`:

- `opcache.enable=1`. Some distributions and the official Docker images up to PHP 8.4 ship OPcache without loading it; add `zend_extension=opcache` there. PHP 8.5 has it built in.
- `opcache.validate_timestamps=1`. The proxy invalidates its compiled configuration itself; with `0`, restart PHP-FPM after each deployment.
- `realpath_cache_size=4096K` (the default since PHP 7.3).
- Preloading, commented out: `opcache.preload` pointing at [`config/php/preload.php`](../config/php/preload.php) and `opcache.preload_user`.

**Preloading** (PHP 7.4 and later; PHP 7.2 and 7.3 ignore it) compiles and links the classes of a request once, when PHP-FPM starts. The script loads the proxy (not its console commands), the image library, `psr/log` and the core of `symfony/http-foundation`, and skips any class that does not load, so it cannot stop PHP-FPM from starting. Preloaded classes are fixed until PHP-FPM restarts: restart it after every deployment or `composer install`. One PHP-FPM master preloads for all of its pools, so enable it only where no other application of that master uses other versions of the same packages.

Install the dependencies for production with:

```bash
composer install --no-dev --classmap-authoritative
```

The authoritative class map lets the autoloader find every class without searching the filesystem.

If the pool sets `request_terminate_timeout`, keep it above the coalescing timeout plus the conversion budget (see [Image variants](caching.md#image-variants-and-the-pending-tier)): it counts from the start of the request and kills the worker when it is reached.

## Trusted proxies and the HTTPS redirect

Both web server configurations redirect plain-HTTP requests to https with a `301`. Behind a TLS-terminating reverse proxy or load balancer, the web server must believe that proxy's `X-Forwarded-Proto: https`, and only that proxy's: any client can send the header. List the same addresses in three places:

1. `TRUSTED_PROXIES` of the proxy configuration, which PHP uses for the client address and the scheme.
2. Apache: both virtual hosts of `config/apache/proxy-cdn.conf`:

   ```apache
   SetEnvIfExpr "-R '10.0.0.0/8' && %{HTTP:X-Forwarded-Proto} == 'https'" PROXYCDN_HTTPS=on
   ```

3. nginx: the `geo $proxycdn_trusted_proxy` block of `proxy-cdn.conf`, which covers its server block on `:80` and `:443`:

   ```nginx
   geo $proxycdn_trusted_proxy {
       default 0;
       10.0.0.0/8 1;
   }
   ```

Without them `X-Forwarded-Proto` is ignored. That is the safe default, but a TLS-terminating proxy then gets a redirect loop until it is listed.

When the direct peer is a trusted proxy, the client address is the nearest `X-Forwarded-For` entry, read from right to left, that is not itself a trusted proxy. `X-Forwarded-Host` is never read.

## Sending files from the web server

With `SENDFILE=x-accel-redirect` (nginx) or `SENDFILE=x-sendfile` (Apache with `mod_xsendfile`), the cache files that PHP chooses (managed entries, query strings the web server cannot map, pending images) are sent by the web server rather than streamed by PHP. PHP hands a file over only when the web server sets the server variable `PROXYCDN_SENDFILE` to the same mode:

- nginx: `proxy-cdn-fastcgi.conf` passes it, and the internal `/_cache/` location of `proxy-cdn-locations.conf` serves the file.
- Apache: uncomment the `mod_xsendfile` block of `config/apache/proxy-cdn.conf`, which sets `XSendFile On`, `XSendFilePath` to `public/_cache` and `PROXYCDN_SENDFILE`.

Otherwise PHP streams the file itself, so a missing module never produces empty responses. A client cannot set the variable: request headers become `HTTP_*` variables.

## Response headers

A hit carries the same headers whoever serves it:

| Header | Value |
| --- | --- |
| `Cache-Control` | `public, max-age=31536000, must-revalidate` from the web server; `CACHE_EXPIRY` (or less for a managed entry) from PHP |
| `X-Cache-Status` | `HIT` (see [X-Cache-Status](caching.md#x-cache-status) for the values PHP sends) |
| `Access-Control-Allow-Origin` | `*` |
| `Cross-Origin-Resource-Policy` | `cross-origin` |
| `Timing-Allow-Origin` | `*` |
| `X-Content-Type-Options` | `nosniff` |
| `Content-Security-Policy` | `default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox`, on SVG only |
| `Vary` | `Accept` on raster images, `Accept-Encoding` on compressible types |
| `Content-Encoding` | `br` or `gzip` on an encoding |
| `Content-Type` | from the extension, with the same table in PHP, Apache and nginx, whatever the origin sent |

PHP adds `X-Request-Id` to every response it sends. When you change `CACHE_EXPIRY`, change the `max-age` of `public/.htaccess` and `config/nginx/proxy-cdn-headers.conf` with it; `check-env` warns when they differ.

**HSTS.** nginx sends `Strict-Transport-Security: max-age=31536000` from its server block, which covers the responses of PHP; for the hits, uncomment the same line in `proxy-cdn-asset-headers.conf`. Apache sends it when the `HTTPS` variable is set, that is, on connections that Apache terminates with TLS itself; behind a TLS-terminating proxy, let that proxy send it. Both configurations send it for the host only: `includeSubDomains` and `preload` affect sibling host names, and stay commented out.

## Several instances behind a load balancer

A single box is the default. To scale horizontally, run several instances behind a load balancer and list the others in `CLUSTER_PEERS` of each: every purge one instance accepts is then sent to its peers (see [Running several instances](../README.md#running-several-instances)). Each instance keeps its own `public/_cache` and `var/`; never share them between instances.

[`docker/cluster/`](../docker/cluster/) is a working example: two instances (nginx and PHP-FPM each) behind an nginx load balancer, a stand-in origin, and a private CA for the fan-out.

```bash
# vendor/ installed for the PHP version of the image (8.5 by default)
cd docker/cluster
./setup.sh                # certificates in tls/, data directories, .env
docker compose up -d
curl -H 'Host: cdn.example.test' http://127.0.0.1:8080/site.css
```

- The load balancer listens on `127.0.0.1:8080` (`CLUSTER_PORT`), forwards to port 80 of each node and sends `X-Forwarded-Proto: https`; each node lists it in its `geo` block and in `TRUSTED_PROXIES`.
- The nodes listen on `8443` for the fan-out, with certificates signed by the CA that `setup.sh` creates; expose that port to the other instances only.
- PHP runs as `CLUSTER_UID`:`CLUSTER_GID`, the owner of `data/`: the user running `setup.sh`, or `33:33` (`www-data` of the image) when that is root. Set them before `setup.sh` to choose another.
- [`node.env`](../docker/cluster/node.env) is the configuration of both nodes; replace its keys before any real use. It sets `IMAGE_CONVERTER=none`, because the `php` image has no conversion backend.

Request coalescing, the circuit breaker and the miss rate limit are per instance: with n instances the origin may see up to n fetches of one cold URL, and each instance counts its own breaker failures. `cache:warmup`, `cache:evict` and `cache:purge --all` act on one instance at a time.

## Embedding the kernel in another application

`Mfonte\ProxyCdn\Http\ProxyKernel` (and `EdgeKernel`, which adds `/healthz`, `/metrics`, the request ID and the purge fan-out) turns a Symfony `Request` into a `Response`. It never sends the response, never exits and reads nothing but the request, so a framework can mount it. The CI runs this route in a fresh Laravel application:

```php
Route::get('/{path}', function (\Illuminate\Http\Request $request) {
    $config = (new \Mfonte\ProxyCdn\Config\ConfigLoader(null))->loadFromArray([
        'SOURCE_ORIGIN' => 'https://origin.example.com',
        'PROXY_HOSTS' => 'cdn.example.com',
        'CACHEABLE_EXTENSIONS' => 'css,js,png,jpg',
        'UPSTREAM_VERIFY_TLS' => 'true',
        'IMAGE_CONVERTER' => 'none',
    ]);
    $logger = app('log');
    $kernel = \Mfonte\ProxyCdn\Http\ProxyKernelFactory::create(
        $config,
        storage_path('proxy-cdn'),
        storage_path('proxy-cdn-state'),
        \Mfonte\ProxyCdn\Origin\HttpOriginFetcher::fromConfig($config, $logger),
        null,
        $logger
    );

    return $kernel->handle($request);
})->where('path', '.*');
```

- `loadFromArray()` validates values the application holds itself, without reading the environment or `.env`.
- The second argument is the document root (entries go to its `_cache/` directory), the third the state directory; keep the state directory out of any public path.
- `ProxyKernel::terminate()` runs the work left for after the response (background revalidations, image conversions). Call it once the response has been sent.
- The process-wide settings (`CHARSET`, `TIMEZONE`, `LOCALE`, `DEBUG`) are applied only by the standalone front controller; an embedding application keeps its own.
- Composer reads repositories from the root package only, so the application must declare the VCS repository of `mfonte/secure-image-converter` and require it with the constraint of the proxy's [`composer.json`](../composer.json), in addition to the proxy itself.
