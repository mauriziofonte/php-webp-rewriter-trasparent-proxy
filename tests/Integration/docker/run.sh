#!/usr/bin/env bash
#
# Integration harness entry point: starts the proxy behind Apache + PHP-FPM
# and behind nginx + PHP-FPM (compose.yaml), checks the fake origin, then runs
# a smoke test on each stack: the HTTP client negotiates HTTP/2 with the
# origin over TLS from PHP-FPM, the first request is fetched from the origin
# through PHP-FPM, the second is served from the cache by the web server
# without reaching PHP-FPM or the origin.
#
# Usage: tests/Integration/docker/run.sh [--php <version>] [--stack apache|nginx|all] [--keep]
#
#   --php     PHP-FPM image version (default 8.5)
#   --stack   which stack to test (default all)
#   --keep    leave the containers and HARNESS_WORKDIR in place afterwards
#
# Nothing is installed at runtime: the official images only receive
# configuration through read-only mounts, and vendor/ is mounted from this
# checkout, so install it beforehand for a version that runs on --php (for
# example "composer update --no-dev" with that PHP version).
#
# Environment:
#   HARNESS_IMAGE_MIRROR  registry the images are pulled from, then tagged with
#                         their Docker Hub names (default mirror.gcr.io/library;
#                         set it empty to pull from Docker Hub)
#   HARNESS_WORKDIR       empty directory for the application copies and the
#                         logs; it is kept (default: a temporary directory,
#                         removed at the end unless --keep)

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../../.." && pwd)"
fixture="$root/tests/Fixtures/origin/images/gradient.png"
asset=/images/gradient.png

php_version=8.5
stack=all
keep=0

log() { printf '==> %s\n' "$*"; }
pass() { printf '    ok: %s\n' "$*"; }
die() {
    printf 'FAILED: %s\n' "$*" >&2
    exit 1
}
usage() { sed -n '9,13p' "$0" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
    case "$1" in
        --php) php_version="${2:-}"; shift 2 || die "--php needs a version" ;;
        --php=*) php_version="${1#*=}"; shift ;;
        --stack) stack="${2:-}"; shift 2 || die "--stack needs a value" ;;
        --stack=*) stack="${1#*=}"; shift ;;
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

for tool in docker curl git cmp openssl; do
    command -v "$tool" >/dev/null || die "$tool is required"
done
docker compose version >/dev/null 2>&1 || die "the docker compose plugin is required"
[ -f "$root/vendor/autoload.php" ] || die "vendor/ is missing: install the dependencies first (see the header of this script)"

# --- working directory ------------------------------------------------------

own_workdir=0
if [ -n "${HARNESS_WORKDIR:-}" ]; then
    mkdir -p "$HARNESS_WORKDIR"
    [ -z "$(ls -A "$HARNESS_WORKDIR")" ] || die "HARNESS_WORKDIR must be empty: $HARNESS_WORKDIR"
    workdir="$(cd "$HARNESS_WORKDIR" && pwd)"
else
    workdir="$(mktemp -d "${TMPDIR:-/tmp}/proxycdn-harness.XXXXXX")"
    own_workdir=1
fi
chmod 755 "$workdir"

# PHP-FPM runs as the caller so that the caller can remove what it writes.
# When the caller is root, it runs as www-data of the Debian-based images.
if [ "$(id -u)" -eq 0 ]; then
    fpm_uid=33
    fpm_gid=33
else
    fpm_uid="$(id -u)"
    fpm_gid="$(id -g)"
fi

export HARNESS_PHP_VERSION="$php_version" HARNESS_UID="$fpm_uid" HARNESS_GID="$fpm_gid" HARNESS_WORKDIR="$workdir"
project="proxycdn-harness-php${php_version//./}-$$"
compose() { docker compose --project-name "$project" --file "$here/compose.yaml" "$@"; }

services=(origin)
for s in "${stacks[@]}"; do
    services+=("fpm-$s" "$s")
done

started=0
cleanup() {
    local status=$?
    set +e
    if [ "$started" -eq 1 ]; then
        if [ "$status" -ne 0 ]; then
            log "Diagnostics"
            compose ps --all
            compose logs --no-color --timestamps >"$workdir/compose.log" 2>&1
            tail -n 80 "$workdir/compose.log"
            for file in "$workdir"/*/logs/*; do
                if [ -s "$file" ]; then
                    printf -- '--- %s\n' "$file"
                    tail -n 40 "$file"
                fi
            done
        fi
        if [ "$keep" -eq 1 ]; then
            log "Kept project $project and $workdir. Remove them with:"
            printf '    docker compose --project-name %s down --volumes && rm -rf %s\n' "$project" "$workdir"
        else
            compose down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1
        fi
    fi
    if [ "$own_workdir" -eq 1 ] && [ "$keep" -eq 0 ]; then
        rm -rf "$workdir"
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# --- images and vendor/ -----------------------------------------------------

mirror="${HARNESS_IMAGE_MIRROR-mirror.gcr.io/library}"
log "Images (${mirror:-Docker Hub})"
for image in $(compose config --images "${services[@]}" | sort -u); do
    source_image="${mirror:+$mirror/}$image"
    if ! docker image inspect "$source_image" >/dev/null 2>&1; then
        docker pull --quiet "$source_image" >/dev/null || die "cannot pull $source_image"
    fi
    if [ "$source_image" != "$image" ]; then
        docker tag "$source_image" "$image"
    fi
    pass "$image"
done

log "vendor/ on PHP $php_version"
docker run --rm --network none --volume "$root:/app:ro" "php:$php_version-fpm" \
    php -r 'require "/app/vendor/autoload.php";' ||
    die "vendor/ does not load on PHP $php_version: install it for that version first"
pass "vendor/autoload.php loads"

# --- application copies -----------------------------------------------------

# Tracked and untracked files that git does not ignore, so local changes are
# tested while cache entries left in public/ by a manual run are not copied.
copy_application() {
    local target="$1"
    mkdir -p "$target/vendor"
    (cd "$root" && git ls-files -z --cached --others --exclude-standard -- . \
        ':(exclude)tests' ':(exclude)tools' ':(exclude).github') |
        while IFS= read -r -d '' file; do
            if [ -e "$root/$file" ]; then
                printf '%s\0' "$file"
            fi
        done |
        tar -C "$root" --null --files-from=- -cf - | tar -C "$target" -xf -
    cp "$here/app.env" "$target/.env"
}

for s in "${stacks[@]}"; do
    copy_application "$workdir/$s/app"
    mkdir -p "$workdir/$s/logs"
    chmod -R u+rwX,go+rX,go-w "$workdir/$s"
    if [ "$(id -u)" -eq 0 ]; then
        chown -R "$fpm_uid:$fpm_gid" "$workdir/$s"
    fi
done

# The certificate of the TLS port of the fake origin, for the name "origin".
mkdir -p "$workdir/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=origin \
    -addext subjectAltName=DNS:origin -addext basicConstraints=critical,CA:TRUE \
    -keyout "$workdir/tls/origin.key" -out "$workdir/tls/origin.crt" >/dev/null 2>&1 ||
    die "openssl cannot generate the origin certificate"
chmod 644 "$workdir/tls/origin.key" "$workdir/tls/origin.crt"

# --- start ------------------------------------------------------------------

log "Starting ${services[*]} (PHP $php_version)"
started=1
compose up --detach --pull never "${services[@]}" || die "docker compose up failed"

port_of() { compose port "$1" 80 | sed -n 's/.*:\([0-9][0-9]*\)$/\1/p' | head -n 1; }
status_of() { curl -sS -o /dev/null -w '%{http_code}' "$@"; }

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
web_ready() { [ "$(status_of "$1/.htaccess")" = 403 ]; }

wait_for "origin is up" 30 origin_ready
for s in "${stacks[@]}"; do
    wait_for "fpm-$s is up" 60 fpm_ready "$s"
    wait_for "$s is up" 30 web_ready "http://127.0.0.1:$(port_of "$s")"
done

# --- fake origin self-test --------------------------------------------------

origin_count() { curl -fsS "$origin/__origin/count${1:+?path=$1}"; }
origin_reset() { curl -fsS -X POST "$origin/__origin/reset" >/dev/null; }

log "Fake origin"
origin_reset
expect "serves the fixture" "$(curl -sS -o "$workdir/origin.body" -w '%{http_code}' "$origin$asset")" 200
cmp -s "$workdir/origin.body" "$fixture" || die "the fake origin body differs from the fixture"
expect "404 for a missing file" "$(status_of "$origin/images/missing.png")" 404
expect "status from the query string" "$(status_of "$origin$asset?origin_status=503")" 503
redirect="$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' "$origin$asset?origin_status=302&origin_location=/moved.png")"
case "$redirect" in
    "302 "*/moved.png) pass "redirect to origin_location" ;;
    *) die "redirect to origin_location: got '$redirect'" ;;
esac
elapsed="$(curl -sS -o /dev/null -w '%{time_total}' "$origin$asset?origin_delay=400")"
awk -v t="$elapsed" 'BEGIN { exit !(t >= 0.4) }' || die "origin_delay=400 answered after ${elapsed}s"
pass "delay from the query string (${elapsed}s)"
curl -fsS -X PUT "$origin/__origin/rules?path=$asset&status=500" >/dev/null
expect "stored rule" "$(status_of "$origin$asset")" 500
curl -fsS -X DELETE "$origin/__origin/rules?path=$asset" >/dev/null
expect "rule removed" "$(status_of "$origin$asset")" 200
expect "count per path" "$(origin_count "$asset")" 6
expect "total count" "$(origin_count)" 7
origin_reset
expect "reset" "$(origin_count)" 0

# --- smoke test per stack ---------------------------------------------------

lines_of() { if [ -f "$1" ]; then wc -l <"$1" | tr -d ' '; else echo 0; fi; }
has_lines() { [ "$(lines_of "$1")" -ge "$2" ]; }
cache_status() { grep -i '^x-cache-status:' "$1" | cut -d: -f2- | tr -d ' \r' | paste -sd, - || true; }

# fetch <url> <output prefix>: prints the status, keeps headers and body.
fetch() {
    curl -sS -H 'X-Forwarded-Proto: https' -D "$2.headers" -o "$2.body" -w '%{http_code}' "$1"
}

smoke() {
    local s="$1" base fpm_log lines
    base="http://127.0.0.1:$(port_of "$s")"
    fpm_log="$workdir/$s/logs/php-fpm-access.log"
    log "Smoke test: $s + PHP-FPM $php_version"
    origin_reset

    expect "$s: plain HTTP is redirected to https" \
        "$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' "$base$asset")" \
        "301 https://${base#http://}$asset"
    expect "$s: vendor/ is refused" "$(status_of -H 'X-Forwarded-Proto: https' "$base/vendor/autoload.php")" 403

    lines="$(lines_of "$fpm_log")"
    expect "$s: first request" "$(fetch "$base$asset" "$workdir/$s/first")" 200
    cmp -s "$workdir/$s/first.body" "$fixture" || die "$s: the first response differs from the origin file"
    expect "$s: first request fetched from the origin" "$(origin_count "$asset")" 1
    wait_for "$s: first request went through PHP-FPM" 10 has_lines "$fpm_log" $((lines + 1))
    [ -f "$workdir/$s/app/public$asset" ] || die "$s: no cache entry at public$asset"
    pass "$s: cache entry written to public$asset"

    expect "$s: second request" "$(fetch "$base$asset" "$workdir/$s/second")" 200
    cmp -s "$workdir/$s/second.body" "$fixture" || die "$s: the second response differs from the origin file"
    expect "$s: second request served without the origin" "$(origin_count "$asset")" 1
    # PHP-FPM writes its access log after the response: leave it time to do
    # so before concluding that the second request never reached it.
    sleep 1
    expect "$s: second request served without PHP-FPM" "$(lines_of "$fpm_log")" $((lines + 1))

    printf '    info: X-Cache-Status first=%s second=%s\n' \
        "$(cache_status "$workdir/$s/first.headers")" "$(cache_status "$workdir/$s/second.headers")"
}

# The transports from PHP-FPM against the TLS port of the origin: curl
# negotiates HTTP/2 through ALPN, streams speak HTTP/1.1, and both store the
# fixture unchanged.
client() {
    local s="$1" output digest
    log "Origin client: fpm-$s (PHP $php_version)"
    output="$(compose exec -T "fpm-$s" php /etc/harness/origin-protocol.php /app/vendor/autoload.php 2>&1)" || die "fpm-$s: $output"
    digest="$(openssl dgst -sha256 -r "$fixture" | cut -d' ' -f1)"
    while read -r name outcome version body; do
        expect "fpm-$s: $name fetched the fixture" "$outcome $body" "success $digest"
        pass "fpm-$s: $name over $version"
    done <<<"$output"
}

for s in "${stacks[@]}"; do
    client "$s"
    smoke "$s"
done

log "Integration smoke test passed on PHP $php_version (${stacks[*]})"
