#!/usr/bin/env bash
#
# Performance suite (plan section 10): starts the proxy behind Apache +
# PHP-FPM and behind nginx + PHP-FPM with the integration harness
# (tests/Integration/docker) plus compose.yaml of this directory, then
# measures on each stack, with k6 in a container of the same network:
#
#   1. a warm hit served by the web server against the same file served as
#      a plain static file by the same server (p50 and p99 at a fixed rate,
#      requests per second at saturation), in alternating rounds;
#   2. a warm hit served by PHP: server-side latency from the PHP-FPM
#      access log;
#   3. cold misses against a fake origin with a fixed latency: total latency
#      minus that latency (no conversion: IMAGE_CONVERSION_MODE is none);
#   4. the thundering herd: concurrent requests on one cold entry, whose
#      origin answers after a second, cause exactly one origin fetch.
#
# report.php prints the results and the targets, writes summary.json and
# applies the gate (see "--max-ratio").
#
# Usage: tests/Performance/run.sh [options]
#
#   --php <version>        PHP-FPM image version (default 8.5)
#   --stack <name>         apache, nginx or all (default all)
#   --preload on|off       OPcache preloading on PHP 7.4+ (default on)
#   --apache-htaccess      Apache reads public/.htaccess on every request
#                          (AllowOverride All) instead of including it in
#                          the virtual host, as config/apache/proxy-cdn.conf
#                          recommends
#   --rounds <n>           rounds of the hit and baseline measurements (default 3);
#                          the gate takes the best one
#   --duration <time>      duration of each of those measurements (default 10s)
#   --rate <n>             requests per second of the latency measurements (default 1000)
#   --vus <n>              clients of the throughput measurements (default 16)
#   --php-rate <n>         requests per second of the PHP hits (default 200)
#   --misses <n>           cold misses measured (default 300)
#   --origin-delay <ms>    fixed latency of the origin for the misses (default 50)
#   --herd <n>             concurrent requests of the herd (default 500)
#   --max-ratio [<stack>=]<x>
#                          gate: in its best round, the p50 of the hit may be at
#                          most x times the baseline; repeat it with a stack name
#                          (apache=1.50) to set one stack (default 1.10, the target)
#   --max-p99-ratio [<stack>=]<x>
#                          gate: the same for p99 (default 1.10)
#   --min-rps-ratio [<stack>=]<x>
#                          gate: in its best round, the hit reaches at least x times
#                          the requests per second of the baseline (default 0.90)
#   --strict               the absolute targets (PHP hit, cold miss) gate too
#   --results <dir>        keep the results there (default: inside the work dir)
#   --keep                 leave the containers and the work dir in place
#
# Install vendor/ beforehand for --php, as in production:
#   composer update --no-dev --classmap-authoritative
# The suite records which autoloader it measured.
#
# Environment: HARNESS_IMAGE_MIRROR and HARNESS_WORKDIR as for the
# integration harness; BENCH_K6_VERSION and BENCH_K6_DIGEST override the
# pinned k6 image (grafana/k6).

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../.." && pwd)"
harness="$root/tests/Integration/docker"
asset=/images/gradient.png

# The load generator, pinned by version and by the digest of its index.
k6_version="${BENCH_K6_VERSION:-1.3.0}"
k6_digest="${BENCH_K6_DIGEST:-sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d}"

php_version=8.5
stack=all
preload=on
apache_rules=vhost
rounds=3
duration=10s
rate=1000
vus=16
php_rate=200
misses=300
origin_delay=50
herd=500
report_options=()
results=""
keep=0

log() { printf '==> %s\n' "$*"; }
pass() { printf '    ok: %s\n' "$*"; }
die() {
    printf 'FAILED: %s\n' "$*" >&2
    exit 1
}
usage() { sed -n '18,50p' "$0" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
    case "$1" in
        --php) php_version="${2:-}"; shift 2 || die "--php needs a value" ;;
        --stack) stack="${2:-}"; shift 2 || die "--stack needs a value" ;;
        --preload) preload="${2:-}"; shift 2 || die "--preload needs a value" ;;
        --apache-htaccess) apache_rules=htaccess; shift ;;
        --rounds) rounds="${2:-}"; shift 2 || die "--rounds needs a value" ;;
        --duration) duration="${2:-}"; shift 2 || die "--duration needs a value" ;;
        --rate) rate="${2:-}"; shift 2 || die "--rate needs a value" ;;
        --vus) vus="${2:-}"; shift 2 || die "--vus needs a value" ;;
        --php-rate) php_rate="${2:-}"; shift 2 || die "--php-rate needs a value" ;;
        --misses) misses="${2:-}"; shift 2 || die "--misses needs a value" ;;
        --origin-delay) origin_delay="${2:-}"; shift 2 || die "--origin-delay needs a value" ;;
        --herd) herd="${2:-}"; shift 2 || die "--herd needs a value" ;;
        --max-ratio | --max-p99-ratio | --min-rps-ratio)
            [[ "${2:-}" =~ ^([a-z]+=)?[0-9]+(\.[0-9]+)?$ ]] || die "$1 expects a ratio such as 1.25 or apache=1.50"
            report_options+=("$1" "$2")
            shift 2
            ;;
        --strict) report_options+=(--strict); shift ;;
        --results) results="${2:-}"; shift 2 || die "--results needs a value" ;;
        --keep) keep=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; die "unknown argument: $1" ;;
    esac
done

[[ "$php_version" =~ ^[0-9]+\.[0-9]+$ ]] || die "--php expects a version such as 8.5, got '$php_version'"
case "$stack" in
    all) stacks=(apache nginx) ;;
    apache | nginx) stacks=("$stack") ;;
    *) die "--stack expects apache, nginx or all, got '$stack'" ;;
esac
case "$preload" in on | off) ;; *) die "--preload expects on or off, got '$preload'" ;; esac
for value in "$rounds" "$rate" "$vus" "$php_rate" "$misses" "$origin_delay" "$herd"; do
    [[ "$value" =~ ^[1-9][0-9]*$ ]] || die "expected a positive integer, got '$value'"
done
[[ "$duration" =~ ^[1-9][0-9]*[sm]$ ]] || die "--duration expects a time such as 10s, got '$duration'"

for tool in docker curl git php; do
    command -v "$tool" >/dev/null || die "$tool is required"
done
docker compose version >/dev/null 2>&1 || die "the docker compose plugin is required"
[ -f "$root/vendor/autoload.php" ] || die "vendor/ is missing: install the dependencies first (see the header of this script)"
if grep -q 'setClassMapAuthoritative(true)' "$root/vendor/composer/autoload_real.php"; then
    autoloader=classmap-authoritative
else
    autoloader=psr-4
    printf 'warning: vendor/ is not installed with --classmap-authoritative, as production should be\n' >&2
fi

# --- working directory ------------------------------------------------------

own_workdir=0
if [ -n "${HARNESS_WORKDIR:-}" ]; then
    mkdir -p "$HARNESS_WORKDIR"
    [ -z "$(ls -A "$HARNESS_WORKDIR")" ] || die "HARNESS_WORKDIR must be empty: $HARNESS_WORKDIR"
    workdir="$(cd "$HARNESS_WORKDIR" && pwd)"
else
    workdir="$(mktemp -d "${TMPDIR:-/tmp}/proxycdn-bench.XXXXXX")"
    own_workdir=1
fi
chmod 755 "$workdir"
if [ -z "$results" ]; then
    results="$workdir/results"
fi
mkdir -p "$results" "$workdir/results"
results="$(cd "$results" && pwd)"

# PHP-FPM and k6 run as the caller, or as www-data when the caller is root.
if [ "$(id -u)" -eq 0 ]; then
    fpm_uid=33
    fpm_gid=33
else
    fpm_uid="$(id -u)"
    fpm_gid="$(id -g)"
fi

export HARNESS_PHP_VERSION="$php_version" HARNESS_UID="$fpm_uid" HARNESS_GID="$fpm_gid" HARNESS_WORKDIR="$workdir"
export BENCH_K6_VERSION="$k6_version"
project="proxycdn-bench-php${php_version//./}-$$"
compose() {
    docker compose --project-name "$project" --file "$harness/compose.yaml" --file "$here/compose.yaml" "$@"
}

services=(origin)
for s in "${stacks[@]}"; do
    services+=("fpm-$s" "$s")
done

started=0
reported=0
cleanup() {
    local status=$?
    set +e
    if [ "$started" -eq 1 ]; then
        # A failed gate is a result, not an error of the suite.
        if [ "$status" -ne 0 ] && [ "$reported" -eq 0 ]; then
            log "Diagnostics"
            compose ps --all
            compose logs --no-color --timestamps >"$workdir/compose.log" 2>&1
            tail -n 60 "$workdir/compose.log"
        fi
        if [ "$keep" -eq 1 ]; then
            log "Kept project $project and $workdir. Remove them with:"
            printf '    docker compose --project-name %s down --volumes && rm -rf %s\n' "$project" "$workdir"
        else
            compose down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1
        fi
    fi
    if [ "$own_workdir" -eq 1 ] && [ "$keep" -eq 0 ]; then
        case "$results" in
            "$workdir"/*) ;;
            *) rm -rf "$workdir" ;;
        esac
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# --- images -----------------------------------------------------------------

mirror="${HARNESS_IMAGE_MIRROR-mirror.gcr.io/library}"
log "Images (${mirror:-Docker Hub})"
for image in $(compose config --images "${services[@]}" | sort -u) "grafana/k6:$k6_version@$k6_digest"; do
    case "$image" in
        grafana/*) source_image="${mirror:+${mirror%/library}/}$image" ;;
        *) source_image="${mirror:+$mirror/}$image" ;;
    esac
    if ! docker image inspect "$source_image" >/dev/null 2>&1; then
        docker pull --quiet "$source_image" >/dev/null || die "cannot pull $source_image"
    fi
    if [ "$source_image" != "${image%@*}" ]; then
        docker tag "$source_image" "${image%@*}"
    fi
    pass "$image"
done

docker run --rm --network none --volume "$root:/app:ro" "php:$php_version-fpm" \
    php -r 'require "/app/vendor/autoload.php";' ||
    die "vendor/ does not load on PHP $php_version: install it for that version first"
pass "vendor/ loads on PHP $php_version ($autoloader autoloader)"

# --- PHP settings: config/php/proxy-cdn.ini ---------------------------------

php_id="$(docker run --rm --network none "php:$php_version-fpm" php -r 'echo PHP_VERSION_ID;')"
# Capture the list first: "php -m | grep -q" fails under pipefail when grep
# exits on the first match and php gets SIGPIPE, which would load OPcache
# twice on PHP 8.5, where it is built in.
php_modules="$(docker run --rm --network none "php:$php_version-fpm" php -m)" ||
    die "php -m failed on PHP $php_version"
mkdir -p "$workdir/php"
{
    if ! grep -qx 'Zend OPcache' <<<"$php_modules"; then
        echo 'zend_extension=opcache'
    fi
    if [ "$preload" = on ] && [ "$php_id" -ge 70400 ]; then
        sed -e 's|^;opcache.preload=.*|opcache.preload=/app/config/php/preload.php|' \
            -e 's|^;opcache.preload_user=.*|opcache.preload_user=www-data|' "$root/config/php/proxy-cdn.ini"
    else
        cat "$root/config/php/proxy-cdn.ini"
    fi
} >"$workdir/php/proxy-cdn.ini"
if grep -q '^opcache.preload=' "$workdir/php/proxy-cdn.ini"; then
    preloading=on
else
    preloading=off
fi

# --- application copies -----------------------------------------------------

# As the harness: tracked and untracked files that git does not ignore.
for s in "${stacks[@]}"; do
    target="$workdir/$s/app"
    mkdir -p "$target/vendor" "$workdir/$s/logs"
    (cd "$root" && git ls-files -z --cached --others --exclude-standard -- . \
        ':(exclude)tests' ':(exclude)tools' ':(exclude).github') |
        while IFS= read -r -d '' file; do
            if [ -e "$root/$file" ]; then
                printf '%s\0' "$file"
            fi
        done |
        tar -C "$root" --null --files-from=- -cf - | tar -C "$target" -xf -
    # The load generator names the web servers by their service names.
    grep -v '^PROXY_HOSTS' "$harness/app.env" >"$target/.env"
    printf 'PROXY_HOSTS = "apache,nginx"\n' >>"$target/.env"
    if [ "$s" = nginx ]; then
        printf 'SENDFILE = "x-accel-redirect"\n' >>"$target/.env"
    else
        printf 'SENDFILE = "x-sendfile"\n' >>"$target/.env"
    fi
    if [ "$s" = apache ]; then
        if [ "$apache_rules" = vhost ]; then
            printf '<Directory "/app/public">\n    AllowOverride None\n    Include /app/public/.htaccess\n</Directory>\n' \
                >"$workdir/$s/rules.conf"
        else
            : >"$workdir/$s/rules.conf"
        fi
    fi
    chmod -R u+rwX,go+rX,go-w "$workdir/$s"
done
chmod 777 "$workdir/results"
if [ "$(id -u)" -eq 0 ]; then
    for s in "${stacks[@]}"; do
        chown -R "$fpm_uid:$fpm_gid" "$workdir/$s"
    done
    chown "$fpm_uid:$fpm_gid" "$workdir/results"
fi

# The harness origin also listens with TLS; the suite does not use it, but
# the origin needs a certificate to start.
mkdir -p "$workdir/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=origin \
    -addext subjectAltName=DNS:origin -keyout "$workdir/tls/origin.key" -out "$workdir/tls/origin.crt" >/dev/null 2>&1 ||
    die "openssl cannot generate the origin certificate"
chmod 644 "$workdir/tls/origin.key" "$workdir/tls/origin.crt"

# --- start ------------------------------------------------------------------

log "Starting ${services[*]} (PHP $php_version, preloading $preloading)"
started=1
compose up --detach --pull never "${services[@]}" || die "docker compose up failed"

port_of() { compose port "$1" 80 | sed -n 's/.*:\([0-9][0-9]*\)$/\1/p' | head -n 1; }
wait_for() {
    local what="$1" timeout="$2" deadline
    shift 2
    deadline=$((SECONDS + timeout))
    until "$@" >/dev/null 2>&1; do
        [ "$SECONDS" -lt "$deadline" ] || die "timed out after ${timeout}s waiting for $what"
        sleep 0.2
    done
    pass "$what"
}
expect() {
    if [ "$2" = "$3" ]; then
        pass "$1"
    else
        die "$1: expected '$3', got '$2'"
    fi
}

origin="http://127.0.0.1:$(port_of origin)"
origin_ready() { curl -fsS "$origin/__origin/stats"; }
fpm_ready() { compose exec -T "fpm-$1" php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'; }
web_ready() { [ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'X-Forwarded-Proto: https' "$1/.htaccess")" = 403 ]; }
origin_count() { curl -fsS "$origin/__origin/count${1:+?path=$1}"; }
origin_reset() { curl -fsS -X POST "$origin/__origin/reset" >/dev/null; }
origin_rule() { curl -fsS -X PUT "$origin/__origin/rules?path=$1&delay=$2" >/dev/null; }

wait_for "origin is up" 30 origin_ready
for s in "${stacks[@]}"; do
    wait_for "fpm-$s is up" 60 fpm_ready "$s"
    wait_for "$s is up" 30 web_ready "http://127.0.0.1:$(port_of "$s")"
done

# The PHP settings as PHP-FPM reads them, preloading included: the CLI of
# the same container, with the same php.ini, preloads the same script.
for s in "${stacks[@]}"; do
    status="$(compose exec -T "fpm-$s" php -d opcache.enable_cli=1 -r '
        $status = function_exists("opcache_get_status") ? opcache_get_status(false) : false;
        $classes = is_array($status) && isset($status["preload_statistics"]["classes"]) ? count($status["preload_statistics"]["classes"]) : 0;
        echo (is_array($status) && $status["opcache_enabled"] ? "on" : "off"), " ", $classes;' 2>&1)"
    case "$status" in
        "on 0") [ "$preloading" = off ] || die "fpm-$s: preloading is configured but loaded no class" ;;
        "on "*) [ "$preloading" = on ] || die "fpm-$s: classes are preloaded although preloading is off" ;;
        *) die "fpm-$s: OPcache is not enabled: $status" ;;
    esac
    pass "fpm-$s: OPcache on, ${status#on } classes preloaded"
    if compose logs --no-color "fpm-$s" 2>&1 | grep -Ei 'warning|fatal|error' | grep -vi 'error_log'; then
        die "fpm-$s: PHP-FPM reported the errors above while starting"
    fi
done

# --- measurements -----------------------------------------------------------

# k6 <name> <mode> <url> [VAR=value]...: one measurement.
k6() {
    local name="$1" mode="$2" url="$3" variable
    local -a environment=(-e "NAME=$name" -e "MODE=$mode" -e "URL=$url" -e "DURATION=$duration")
    shift 3
    for variable in "$@"; do
        environment+=(-e "$variable")
    done
    compose --profile load run --rm --no-deps --pull never "${environment[@]}" k6 run --quiet --no-usage-report /scripts/load.js \
        >"$workdir/k6-$name.log" 2>&1 || { tail -n 20 "$workdir/k6-$name.log"; die "k6 failed for $name"; }
    php -r '$s = json_decode((string) file_get_contents($argv[1]), true);
        if (!is_array($s) || $s["failedChecks"] !== 0) { fwrite(STDERR, "failed checks\n"); exit(1); }
        printf("%s: p50 %.3f ms, p99 %.3f ms, %.0f requests per second\n", $argv[2], $s["p50"], $s["p99"], $s["rps"]);' \
        "$workdir/results/$name.json" "$name" | sed 's/^/    /' ||
        { tail -n 20 "$workdir/k6-$name.log"; die "$name: a response was not a 200 with the expected X-Cache-Status"; }
}

lines_of() { if [ -f "$1" ]; then wc -l <"$1" | tr -d ' '; else echo 0; fi; }
quiet_fpm() { sleep 1; expect "$1 without PHP-FPM" "$(lines_of "$2")" "$3"; }

run_id="$(date +%s)"
for s in "${stacks[@]}"; do
    base="http://$s"
    local_base="http://127.0.0.1:$(port_of "$s")"
    fpm_log="$workdir/$s/logs/php-fpm-access.log"
    log "Warm-up: $s + PHP-FPM $php_version"
    origin_reset
    # The rules form under test still redirects plain HTTP to https (with
    # Apache, the rules of public/.htaccess included in the virtual host).
    redirect="$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' -H "Host: $s" "$local_base$asset")"
    expect "$s: plain HTTP is redirected to https" "$redirect" "301 https://$s$asset"
    for url in "$asset" "$asset?v=1&zz=1"; do
        curl -fsS -o /dev/null -H "Host: $s" -H 'X-Forwarded-Proto: https' "$local_base$url" || die "$s: cannot fetch $url"
        status="$(curl -sS -o /dev/null -D - -H "Host: $s" -H 'X-Forwarded-Proto: https' "$local_base$url" |
            grep -i '^x-cache-status:' | tr -d ' \r' | cut -d: -f2)"
        expect "$s: $url is a hit" "$status" HIT
    done

    log "Hits served by $s against static files of $s ($rounds rounds of $duration)"
    lines="$(lines_of "$fpm_log")"
    for round in $(seq "$rounds"); do
        k6 "$s-static-rate-$round" rate "$base:8080$asset" "RATE=$rate"
        k6 "$s-hit-rate-$round" rate "$base$asset" "RATE=$rate" EXPECT_CACHE=HIT
        k6 "$s-static-loop-$round" loop "$base:8080$asset" "VUS=$vus"
        k6 "$s-hit-loop-$round" loop "$base$asset" "VUS=$vus" EXPECT_CACHE=HIT
    done
    quiet_fpm "$s: the hits were served" "$fpm_log" "$lines"

    log "Hits served by PHP behind $s"
    lines="$(lines_of "$fpm_log")"
    k6 "$s-php-hit-rate" rate "$base$asset?v=1&zz=1" "RATE=$php_rate" EXPECT_CACHE=HIT
    sleep 1
    tail -n +$((lines + 1)) "$fpm_log" >"$workdir/results/$s-php-hit-rate.fpm.log"
    expect "$s: every PHP hit is in the PHP-FPM access log" \
        "$(grep -c 'zz=1' "$workdir/results/$s-php-hit-rate.fpm.log" || true)" \
        "$(php -r 'echo json_decode((string) file_get_contents($argv[1]), true)["requests"];' "$workdir/results/$s-php-hit-rate.json")"
    k6 "$s-php-hit-loop" loop "$base$asset?v=1&zz=1" "VUS=$vus" EXPECT_CACHE=HIT

    log "Cold misses behind $s: $misses entries, origin latency ${origin_delay} ms"
    origin_reset
    origin_rule "$asset" "$origin_delay"
    k6 "$s-origin-direct" miss "http://origin$asset" "ITERATIONS=$misses" "RUN=direct-$s-$run_id"
    k6 "$s-miss" miss "$base$asset" "ITERATIONS=$misses" "RUN=$s-$run_id" EXPECT_CACHE=MISS
    expect "$s: one origin fetch per miss" "$(origin_count "$asset")" "$((misses * 2))"

    log "Thundering herd behind $s: $herd concurrent requests on one cold entry"
    origin_reset
    origin_rule /js/app.js 1000
    k6 "$s-herd" herd "$base/js/app.js?v=herd-$run_id" "VUS=$herd"
    printf '{"requests": %d, "originFetches": %d}\n' "$herd" "$(origin_count /js/app.js)" >"$workdir/results/$s-herd.origin.json"
    pass "$s: $(origin_count /js/app.js) origin fetch(es) for $herd requests"
    origin_reset
done

# --- microbenchmarks -------------------------------------------------------

log "Microbenchmarks: configuration and kernel construction (PHP $php_version)"
docker run --rm --network none --user "$fpm_uid:$fpm_gid" -e HOME=/tmp --volume "$root:/app:ro" \
    --volume "$workdir/php/proxy-cdn.ini:/usr/local/etc/php/conf.d/zz-proxy-cdn.ini:ro" "php:$php_version-fpm" \
    php -d opcache.enable_cli=1 /app/tests/Performance/micro.php >"$workdir/results/micro.json" ||
    die "the microbenchmarks failed"
pass "medians written to micro.json"

# --- report -----------------------------------------------------------------

if [ "$results" != "$workdir/results" ]; then
    cp "$workdir"/results/* "$results/"
fi
cat >"$results/run.json" <<EOF
{"php": "$php_version", "phpVersionId": $php_id, "stacks": "${stacks[*]}", "preloading": "$preloading", "apacheRules": "$apache_rules", "autoloader": "$autoloader",
 "rounds": $rounds, "duration": "$duration", "rate": $rate, "vus": $vus, "phpRate": $php_rate, "misses": $misses,
 "originDelayMs": $origin_delay, "herd": $herd, "k6": "$k6_version@$k6_digest",
 "machine": "$(nproc) CPUs, $(uname -sr), $(awk -F': ' '/^model name/ { print $2; exit }' /proc/cpuinfo 2>/dev/null || echo unknown)"}
EOF
reported=1
php "$here/report.php" "$results" ${report_options[@]+"${report_options[@]}"}
