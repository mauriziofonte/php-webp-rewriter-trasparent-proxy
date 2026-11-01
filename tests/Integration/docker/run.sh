#!/usr/bin/env bash
#
# Integration harness entry point: starts the proxy behind Apache + PHP-FPM
# and behind nginx + PHP-FPM (compose.yaml), checks the fake origin, then runs
# a smoke test on each stack: the HTTP client negotiates HTTP/2 with the
# origin over TLS from PHP-FPM, the first request is fetched from the origin
# through PHP-FPM, and every later one is served from the cache by the web
# server without reaching PHP-FPM (its access log stays unchanged) or the
# origin: the entry, its WEBP and AVIF variants, its gzip encoding and the
# entry of a query string, each with the headers PHP sends, once. PURGE,
# OPTIONS and unlisted hosts reach PHP.
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
web_ready() { [ "$(status_of -H 'X-Forwarded-Proto: https' "$1/.htaccess")" = 403 ]; }

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
# count_of <header name> <headers file>: how many times the header is sent.
count_of() { grep -ci "^$1:" "$2" || true; }

# preflight <url> <output prefix>: a CORS preflight; prints the status.
preflight() {
    curl -sS -X OPTIONS -H 'X-Forwarded-Proto: https' -H 'Origin: https://site.example' \
        -H 'Access-Control-Request-Method: GET' -D "$2.headers" -o /dev/null -w '%{http_code}' "$1"
}

# cors_once <label> <headers file> <header>...: each header is sent once.
# Behind Apache + PHP-FPM a "Header set" of .htaccess next to the same
# header from PHP sends it twice ("*, *"), and browsers refuse the answer.
cors_once() {
    local label="$1" file="$2" name
    shift 2
    for name in "$@"; do
        expect "$label: one $name" "$(count_of "$name" "$file")" 1
    done
}

# fetch <url> <output prefix> [curl option]...: prints the status, keeps
# headers and body.
fetch() {
    local url="$1" output="$2"
    shift 2
    curl -sS -H 'X-Forwarded-Proto: https' "$@" -D "$output.headers" -o "$output.body" -w '%{http_code}' "$url"
}

# header_of <name> <headers file>: the values of a header, comma-joined.
header_of() { grep -i "^$1:" "$2" | cut -d: -f2- | sed 's/^ *//' | tr -d '\r' | paste -sd, - || true; }

# varies_on <label> <headers file> <field>: Vary lists the field once.
varies_on() {
    expect "$1" "$(header_of Vary "$2" | tr ',' '\n' | tr -d ' ' | grep -ci "^$3\$" || true)" 1
}

# fpm_quiet <label> <fpm log> <lines before>: PHP-FPM received no request
# since <lines before>. PHP-FPM writes its access log after the response:
# leave it time to do so before concluding that a request never reached it.
fpm_quiet() {
    sleep 1
    expect "$1 without PHP-FPM" "$(lines_of "$2")" "$3"
}

# hit_headers <label> <headers file>: the headers PHP sends with an asset,
# each once, on a file the web server served.
hit_headers() {
    cors_once "$1" "$2" Access-Control-Allow-Origin Cross-Origin-Resource-Policy \
        Timing-Allow-Origin X-Content-Type-Options X-Cache-Status Cache-Control
    expect "$1: nosniff" "$(header_of X-Content-Type-Options "$2")" nosniff
    expect "$1: cross-origin resource policy" "$(header_of Cross-Origin-Resource-Policy "$2")" cross-origin
    case "$(header_of X-Powered-By "$2")" in
        *[0-9]*) die "$1: X-Powered-By carries a version: $(header_of X-Powered-By "$2")" ;;
    esac
    pass "$1: no version in X-Powered-By"
}

smoke() {
    local s="$1" base fpm_log lines app css svg redirect
    base="http://127.0.0.1:$(port_of "$s")"
    fpm_log="$workdir/$s/logs/php-fpm-access.log"
    app="$workdir/$s/app/public/_cache/127.0.0.1"
    log "Smoke test: $s + PHP-FPM $php_version"
    origin_reset

    # Apache keeps the port of the Host header, nginx drops it.
    redirect="$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' "$base$asset")"
    case "$redirect" in
        "301 https://127.0.0.1$asset" | "301 https://${base#http://}$asset") pass "$s: plain HTTP is redirected to https" ;;
        *) die "$s: plain HTTP is redirected to https: got '$redirect'" ;;
    esac
    expect "$s: vendor/ is refused" "$(status_of -H 'X-Forwarded-Proto: https' "$base/vendor/autoload.php")" 403

    lines="$(lines_of "$fpm_log")"
    expect "$s: first request" "$(fetch "$base$asset" "$workdir/$s/first")" 200
    cmp -s "$workdir/$s/first.body" "$fixture" || die "$s: the first response differs from the origin file"
    expect "$s: first request fetched from the origin" "$(origin_count "$asset")" 1
    wait_for "$s: first request went through PHP-FPM" 10 has_lines "$fpm_log" $((lines + 1))
    [ -f "$app$asset" ] || die "$s: no cache entry at public/_cache/127.0.0.1$asset"
    pass "$s: cache entry written to public/_cache/127.0.0.1$asset"

    lines="$(lines_of "$fpm_log")"
    expect "$s: second request" "$(fetch "$base$asset" "$workdir/$s/second")" 200
    cmp -s "$workdir/$s/second.body" "$fixture" || die "$s: the second response differs from the origin file"
    expect "$s: second request served without the origin" "$(origin_count "$asset")" 1
    fpm_quiet "$s: second request served" "$fpm_log" "$lines"

    cors_once "$s: miss" "$workdir/$s/first.headers" Access-Control-Allow-Origin X-Cache-Status
    hit_headers "$s: hit" "$workdir/$s/second.headers"
    expect "$s: the first request is a miss" "$(cache_status "$workdir/$s/first.headers")" MISS
    expect "$s: the second request is a hit" "$(cache_status "$workdir/$s/second.headers")" HIT
    varies_on "$s: a hit varies on Accept" "$workdir/$s/second.headers" Accept

    # Variants: the web server chooses them from Accept. Conversion is off
    # in the harness, so the test writes them itself.
    mkdir -p "$app/.webp/images" "$app/.avif/images"
    printf 'webp variant' >"$app/.webp$asset.webp"
    lines="$(lines_of "$fpm_log")"
    expect "$s: WEBP variant" "$(fetch "$base$asset" "$workdir/$s/webp" -H 'Accept: image/avif;q=0, image/webp')" 200
    expect "$s: WEBP variant body" "$(cat "$workdir/$s/webp.body")" "webp variant"
    expect "$s: WEBP variant type" "$(header_of Content-Type "$workdir/$s/webp.headers")" image/webp
    printf 'avif variant' >"$app/.avif$asset.avif"
    expect "$s: AVIF variant" "$(fetch "$base$asset" "$workdir/$s/avif" -H 'Accept: image/avif,image/webp,*/*')" 200
    expect "$s: AVIF variant body" "$(cat "$workdir/$s/avif.body")" "avif variant"
    varies_on "$s: AVIF variant varies on Accept" "$workdir/$s/avif.headers" Accept
    hit_headers "$s: AVIF variant" "$workdir/$s/avif.headers"
    expect "$s: no variant without Accept" "$(fetch "$base$asset" "$workdir/$s/plain" -H 'Accept: */*')" 200
    cmp -s "$workdir/$s/plain.body" "$fixture" || die "$s: the plain hit differs from the origin file"
    fpm_quiet "$s: variants served" "$fpm_log" "$lines"

    # Query string: the canonical one is a directory the web server finds.
    expect "$s: query string, first request" "$(fetch "$base$asset?v=3" "$workdir/$s/query1")" 200
    expect "$s: query string, first request is a miss" "$(cache_status "$workdir/$s/query1.headers")" MISS
    [ -f "$app/.q/v=3$asset" ] || die "$s: no cache entry at .q/v=3$asset"
    lines="$(lines_of "$fpm_log")"
    expect "$s: query string, second request" "$(fetch "$base$asset?v=3" "$workdir/$s/query2")" 200
    expect "$s: query string, second request is a hit" "$(cache_status "$workdir/$s/query2.headers")" HIT
    fpm_quiet "$s: query string hit served" "$fpm_log" "$lines"
    expect "$s: query string fetched from the origin once" "$(origin_count "$asset")" 2

    # Encodings: the proxy stores a gzip encoding of a text file.
    css=/css/site.css
    expect "$s: stylesheet, first request" "$(fetch "$base$css" "$workdir/$s/css1")" 200
    [ -f "$app/.gz$css" ] || die "$s: no gzip encoding at .gz$css"
    lines="$(lines_of "$fpm_log")"
    expect "$s: gzip encoding" "$(fetch "$base$css" "$workdir/$s/css2" -H 'Accept-Encoding: gzip')" 200
    expect "$s: gzip encoding is labelled" "$(header_of Content-Encoding "$workdir/$s/css2.headers")" gzip
    varies_on "$s: gzip encoding varies on Accept-Encoding" "$workdir/$s/css2.headers" Accept-Encoding
    gzip -dc <"$workdir/$s/css2.body" | cmp -s - "$root/tests/Fixtures/origin$css" ||
        die "$s: the gzip encoding differs from the origin file"
    expect "$s: identity without Accept-Encoding" "$(fetch "$base$css" "$workdir/$s/css3" -H 'Accept-Encoding: identity')" 200
    cmp -s "$workdir/$s/css3.body" "$root/tests/Fixtures/origin$css" || die "$s: the identity hit differs from the origin file"
    hit_headers "$s: gzip encoding" "$workdir/$s/css2.headers"
    # Brotli: the PHP images have no brotli extension, so the harness writes
    # the sibling the proxy would; the web server prefers it when accepted.
    mkdir -p "$(dirname "$app/.br$css")"
    printf 'brotli-sibling' >"$app/.br$css"
    chmod a+r "$app/.br$css"
    expect "$s: Brotli encoding" "$(fetch "$base$css" "$workdir/$s/css4" -H 'Accept-Encoding: gzip, br')" 200
    expect "$s: Brotli encoding is labelled" "$(header_of Content-Encoding "$workdir/$s/css4.headers")" br
    expect "$s: Brotli encoding is the sibling" "$(cat "$workdir/$s/css4.body")" brotli-sibling
    varies_on "$s: Brotli encoding varies on Accept-Encoding" "$workdir/$s/css4.headers" Accept-Encoding
    hit_headers "$s: Brotli encoding" "$workdir/$s/css4.headers"
    expect "$s: gzip when Brotli is refused" "$(fetch "$base$css" "$workdir/$s/css5" -H 'Accept-Encoding: gzip, br;q=0')" 200
    expect "$s: gzip when Brotli is refused is labelled" "$(header_of Content-Encoding "$workdir/$s/css5.headers")" gzip
    fpm_quiet "$s: encodings served" "$fpm_log" "$lines"

    # SVG: the policy of PHP, once.
    svg=/images/logo.svg
    expect "$s: SVG, first request" "$(fetch "$base$svg" "$workdir/$s/svg1")" 200
    lines="$(lines_of "$fpm_log")"
    expect "$s: SVG, second request" "$(fetch "$base$svg" "$workdir/$s/svg2")" 200
    fpm_quiet "$s: SVG hit served" "$fpm_log" "$lines"
    cors_once "$s: SVG miss" "$workdir/$s/svg1.headers" Content-Security-Policy
    cors_once "$s: SVG hit" "$workdir/$s/svg2.headers" Content-Security-Policy
    expect "$s: SVG hit policy" "$(header_of Content-Security-Policy "$workdir/$s/svg2.headers")" \
        "$(header_of Content-Security-Policy "$workdir/$s/svg1.headers")"

    # What must reach PHP even for a cached file.
    lines="$(lines_of "$fpm_log")"
    expect "$s: PURGE of a cached file reaches PHP" \
        "$(status_of -X PURGE -H 'X-Forwarded-Proto: https' "$base$asset")" 401
    expect "$s: preflight of a cached file" "$(preflight "$base$asset" "$workdir/$s/options-hit")" 204
    cors_once "$s: preflight of a cached file" "$workdir/$s/options-hit.headers" \
        Access-Control-Allow-Origin Access-Control-Allow-Methods Access-Control-Max-Age
    expect "$s: unlisted host" "$(status_of -H 'X-Forwarded-Proto: https' -H 'Host: other.example' "$base$asset")" 421
    wait_for "$s: PURGE, OPTIONS and the unlisted host reached PHP-FPM" 10 has_lines "$fpm_log" $((lines + 3))
    expect "$s: preflight of a missing file" "$(preflight "$base/images/missing.png" "$workdir/$s/options-miss")" 204
    cors_once "$s: preflight of a missing file" "$workdir/$s/options-miss.headers" \
        Access-Control-Allow-Origin Access-Control-Allow-Methods Access-Control-Max-Age
    expect "$s: missing file" "$(fetch "$base/images/missing.png" "$workdir/$s/missing")" 404
    expect "$s: an error carries no cache status" "$(cache_status "$workdir/$s/missing.headers")" ""
    case "$(status_of -H 'X-Forwarded-Proto: https' "$base/_cache/127.0.0.1$asset")" in
        200) die "$s: the cache directory is readable directly" ;;
        *) pass "$s: the cache directory is not readable directly" ;;
    esac
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
