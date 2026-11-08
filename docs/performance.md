# Performance

The numbers on this page were measured with the repository's own suite, [`tests/Performance/`](../tests/Performance/), on the code described here. They come from one machine that is not a reference machine; treat them as orders of magnitude and as a way to compare configurations, not as a promise for your hardware.

- [Production settings](#production-settings)
- [The latency suite](#the-latency-suite)
- [Measured results](#measured-results)
- [Targets that are not met](#targets-that-are-not-met)
- [Metrics store cost](#metrics-store-cost)
- [Other measurements](#other-measurements)
- [The CI gate](#the-ci-gate)

## Production settings

- `composer install --no-dev --classmap-authoritative`: the autoloader finds every class in its class map and never searches the filesystem.
- [`config/php/proxy-cdn.ini`](../config/php/proxy-cdn.ini) in the PHP-FPM pool: OPcache on, and preloading with [`config/php/preload.php`](../config/php/preload.php) on PHP 7.4 and later (see [PHP-FPM and OPcache](deployment.md#php-fpm-and-opcache)).
- Apache: include `public/.htaccess` in the virtual host with `AllowOverride None`, as [`config/apache/proxy-cdn.conf`](../config/apache/proxy-cdn.conf) does. With `AllowOverride All`, Apache parses the file on every request.
- nginx: `SENDFILE=x-accel-redirect`, so that the files PHP chooses are sent by nginx.

## The latency suite

```bash
tests/Performance/run.sh --php 8.5
```

The suite needs Docker, and `vendor/` installed for the PHP version under test as in production (`composer update --no-dev --classmap-authoritative`). It starts the Apache and nginx stacks of the integration harness with the settings above and measures, with k6 in a container on the same network:

1. **A hit served by the web server**, against the same file served as a plain static file by the same server: p50 and p99 at a fixed rate (1000 requests per second), and requests per second at saturation (16 clients), in alternating rounds.
2. **A hit served by PHP**: server-side latency, from the PHP-FPM access log, at 200 requests per second.
3. **A cold miss**, against a stand-in origin that answers after a fixed delay: total latency minus that delay, without image conversion.
4. **The thundering herd**: concurrent requests on one cold entry, whose origin answers after a second, must cause exactly one origin fetch.

By default the middleware a production installation runs is on: the metrics store (`METRICS_STORE=file`, `METRICS_KEYS` set), the miss rate limit (set high enough never to refuse) and the request ID. `--middleware off` measures without the metrics store and the rate limit.

It prints a table, writes `summary.json`, and fails when a web-server hit regresses against the static file beyond the given ratios (`--max-ratio` for p50, `--max-p99-ratio`, `--min-rps-ratio`); `--strict` makes the absolute targets fail it too. Run `tests/Performance/run.sh --help` for every option.

Each round measures the hit and then the static file. On a shared machine, the ratios of single rounds spread widely, and a round whose static file was slowed down flatters the hit. The suite therefore compares the best value of each side over the rounds: the lowest p50 and p99 of each, and the highest requests per second of each. Contention only adds latency and removes throughput, so the best values are the least disturbed ones.

## Measured results

Run of `tests/Performance/run.sh` with the options of CI: 5 rounds of 10 seconds, the middleware on, 300 misses against an origin answering after 50 ms, 500 requests in the herd. Machine: a 4-vCPU Xeon 2.1 GHz container, quiet during the runs, with the web servers, PHP-FPM and k6 sharing the same cores.

| Path | Metric | Target | Apache, PHP 7.2 | nginx, PHP 7.2 | Apache, PHP 8.5 | nginx, PHP 8.5 |
| --- | --- | --- | --- | --- | --- | --- |
| Hit served by the web server | p50 / static file | at most 1.10 | 1.22 | 1.16 | 1.21 | 1.11 |
| | p99 / static file | at most 1.10 | 1.27 | 1.25 | 1.10 | 1.08 |
| | requests per second / static file | at least 0.90 | 0.82 | 0.86 | 0.81 | 0.86 |
| Hit served by PHP | server-side p50 | under 2 ms | 1.35 ms | 1.31 ms | 1.07 ms | 1.03 ms |
| | server-side p99 | under 10 ms | 2.80 ms | 2.37 ms | 2.12 ms | 2.43 ms |
| Cold miss | p50 minus origin delay | under 5 ms | 4.29 ms | 3.48 ms | 3.13 ms | 2.28 ms |
| Thundering herd | origin fetches | exactly 1 | 1 | 1 | 1 | 1 |

The ratios are those the suite gates on: the best value of each side over the rounds.

## Targets that are not met

- **Web-server hits against a static file.** The target is a hit within 10% of a static file of the same server. Measured, a hit is 1.11 to 1.22 times the static file at p50 and reaches 0.81 to 0.86 times its requests per second; at p99 it is 1.08 to 1.27 times, within the target on PHP 8.5 only. In absolute terms the gap is small: at 1000 requests per second, the best p50 of a hit was 0.304 ms against 0.251 ms for the static file (Apache, PHP 8.5) and 0.187 ms against 0.169 ms (nginx, PHP 8.5). The difference is the rewrite rules and the negotiation lookups that run before the file is served.
- **Cold miss on Apache.** The overhead of a cold miss moves by a few milliseconds from one run to the next, and on Apache it is sometimes above the 5 ms target: up to 7.1 ms with PHP 7.2.

The PHP-hit targets and the herd guarantee were met on every stack.

## Metrics store cost

With `METRICS_STORE=file`, the default, each request PHP serves adds its counters to `var/metrics/` after the response:

- The latency a client sees does not change: 1.04 ms against 1.06 ms at p50 at 200 requests per second (nginx, PHP 8.5).
- The PHP-FPM worker stays busy 0.32 to 0.43 ms longer per request. The server-side times of the table include it.
- At saturation, PHP serves fewer hits per second. On nginx, where hits through PHP are the cheapest, the cost spread between about 15% and 30% across the suite's runs (2918 against 3713 hits per second, PHP 8.5, in the run of the table). On Apache it was 2% to 7%.

`METRICS_STORE=off` removes this cost, together with the counters of `/metrics`. The hits the web server serves never reach PHP and are not affected either way.

## Other measurements

- **Apache with `AllowOverride All`** instead of the `Include`: hits measured 2.01 times the static file at p50 and 0.46 times its requests per second.
- **Configuration loading** (`tests/Performance/micro.php`): the compiled configuration loads in 37 µs against 119 µs for parsing and validating `.env` on PHP 8.5, and in 58 µs against 141 µs on PHP 7.2. Building the edge kernel with its middleware takes 13 µs (16 µs on PHP 7.2).
- **Origin connections.** On PHP 7.x every miss opens a new connection to the origin and resolves its name again; on PHP 8.5 connections and DNS results are kept between requests (`curl_share_init_persistent()`).

## The CI gate

CI runs the suite on PHP 7.2 and 8.5 with 5 rounds and `--max-ratio 1.50 --max-p99-ratio 3.00 --min-rps-ratio 0.55`. These margins are far above the 10% target, because GitHub-hosted runners are noisy: over 22 stack runs, the ratios were at most 1.33 (p50), at most 2.43 (p99) and at least 0.67 (requests per second), while two known regressions measured outside the margins (`--apache-htaccess` at least 1.76 at p50; nginx with `keepalive_timeout 0` at most 0.49 in requests per second).

**The gate is coarse.** On shared runners it catches a regression of roughly +50% at p50, −45% in requests per second or 3× at p99, not a 10% one. The table of each run, in the job summary, shows the ratios against the target.
