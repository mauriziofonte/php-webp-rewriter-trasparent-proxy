#!/usr/bin/env bash
#
# Integration harness entry point: starts the proxy behind Apache + PHP-FPM
# and behind nginx + PHP-FPM (compose.yaml), checks the fake origin, then runs
# a smoke test on each stack: the HTTP client negotiates HTTP/2 with the
# origin over TLS from PHP-FPM, the first request is fetched from the origin
# through PHP-FPM, and every later one is served from the cache by the web
# server without reaching PHP-FPM (as the route log of the server says) or the
# origin: the entry, its WEBP and AVIF variants, its gzip encoding and the
# entry of a query string, each with the headers PHP sends, once (the first
# request is a resize URL, which must leave the cache enterable). PURGE,
# OPTIONS and unlisted hosts reach PHP, and only a trusted proxy can tell
# that the client used https. Then 500 concurrent requests for one cold
# entry cause exactly one origin fetch (request coalescing).
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
    if [ "$s" = nginx ]; then
        # nginx sends the files PHP chooses.
        printf 'SENDFILE = "x-accel-redirect"\n' >>"$workdir/$s/app/.env"
    else
        # mod_xsendfile is not built into the official Apache image: PHP
        # must stream the files instead of answering empty (fail safe).
        printf 'SENDFILE = "x-sendfile"\n' >>"$workdir/$s/app/.env"
    fi
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
# next_request: a new id for the X-Harness-Request header, which the web
# servers write into their route log (see route_of).
next_request() {
    local n
    n=$(($(cat "$workdir/requests.seq" 2>/dev/null || echo 0) + 1))
    echo "$n" >"$workdir/requests.seq"
    echo "r$n"
}
# request_mark: the id of the last request sent, for the routes checks.
request_mark() { cat "$workdir/requests.seq" 2>/dev/null || echo 0; }
status_of() { curl -sS -o /dev/null -H "X-Harness-Request: $(next_request)" -w '%{http_code}' "$@"; }

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
    curl -sS -X OPTIONS -H "X-Harness-Request: $(next_request)" -H 'X-Forwarded-Proto: https' -H 'Origin: https://site.example' \
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
    curl -sS -H "X-Harness-Request: $(next_request)" -H 'X-Forwarded-Proto: https' "$@" \
        -D "$output.headers" -o "$output.body" -w '%{http_code}' "$url"
}

# header_of <name> <headers file>: the values of a header, comma-joined.
header_of() { grep -i "^$1:" "$2" | cut -d: -f2- | sed 's/^ *//' | tr -d '\r' | paste -sd, - || true; }
# cache_directives <headers file>: the Cache-Control directives, sorted.
cache_directives() { header_of Cache-Control "$1" | tr -d ' ' | tr ',' '\n' | sort | paste -sd, -; }

# varies_on <label> <headers file> <field>: Vary lists the field once.
varies_on() {
    expect "$1" "$(header_of Vary "$2" | tr ',' '\n' | tr -d ' ' | grep -ci "^$3\$" || true)" 1
}

# route_of <id>: "php" when request <id> reached PHP-FPM, "web" when the
# web server answered it alone, from the route log of the web server
# ($web_log), whose line it waits for. Counting the lines of the access
# log of PHP-FPM instead raced with PHP: a worker writes its line once the
# script has ended, after fastcgi_finish_request() has let the client go
# and after the work that follows it, so a count taken right after a miss
# could miss that line and find it on the next count. The route log is
# written per request, with the id the request sent.
route_of() {
    local line deadline=$((SECONDS + 10))
    until line="$(grep -m 1 "^$1 " "$web_log" 2>/dev/null)"; do
        [ "$SECONDS" -lt "$deadline" ] || die "request $1 is not in the route log $web_log"
        sleep 0.05
    done
    case "$(printf '%s' "$line" | cut -d' ' -f2)" in
        proxy* | *:[0-9]*) echo php ;;
        *) echo web ;;
    esac
}

# routes_since <mark>: the route of each request sent after <mark>,
# comma-separated.
routes_since() {
    local n routes="" route
    for n in $(seq $(($1 + 1)) "$(request_mark)"); do
        route="$(route_of "r$n")"
        routes="${routes:+$routes,}$route"
    done
    echo "$routes"
}

# web_only <label> <mark>: every request sent after <mark> was served by
# the web server, without PHP-FPM.
web_only() {
    expect "$1 without PHP-FPM" "$(routes_since "$2" | tr ',' '\n' | sort -u | paste -sd, -)" web
}

# via_php <label> <mark>: every request sent after <mark> reached PHP-FPM.
via_php() {
    expect "$1" "$(routes_since "$2" | tr ',' '\n' | sort -u | paste -sd, -)" php
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

# untrusted_https <stack>: the status of a plain-HTTP request with
# "X-Forwarded-Proto: https" from another container than the trusted proxy.
untrusted_https() {
    compose exec -T "fpm-$1" php -r '
        $context = stream_context_create(["http" => ["follow_location" => 0, "ignore_errors" => true,
            "header" => "X-Forwarded-Proto: https\r\n"]]);
        @file_get_contents($argv[1], false, $context);
        echo explode(" ", $http_response_header[0] ?? "x 000")[1];' "http://$1/images/gradient.png"
}

# php_length <label> <headers file> <size> <stack> [head]: the framing of
# a response of PHP. nginx sends the length PHP declared; Apache never
# trusts it, and sends none on a HEAD and, on a GET, either none
# (chunked) or the length it counted.
php_length() {
    local length
    length="$(header_of Content-Length "$2")"
    if [ "$4" = nginx ]; then
        expect "$1: Content-Length" "$length" "$3"
    elif [ "${5:-}" = head ]; then
        expect "$1: no Content-Length from Apache" "$length" ""
    elif [ -n "$length" ]; then
        expect "$1: Content-Length counted by Apache" "$length" "$3"
    else
        expect "$1: chunked by Apache" "$(header_of Transfer-Encoding "$2")" chunked
    fi
}

# http_response <raw file> <offset> <prefix>: reads the HTTP/1.1 response
# that starts at byte <offset> of the raw file into <prefix>.headers and
# <prefix>.body (de-chunked), and prints the offset that follows it.
http_response() {
    local raw="$1" at="$2" length line chunk
    tail -c +$((at + 1)) "$raw" | sed -n '/^\r$/{p;q};p' >"$3.headers"
    at=$((at + $(wc -c <"$3.headers")))
    length="$(header_of Content-Length "$3.headers")"
    if [ -n "$length" ]; then
        tail -c +$((at + 1)) "$raw" | head -c "$length" >"$3.body"
        at=$((at + length))
    elif [ "$(header_of Transfer-Encoding "$3.headers")" = chunked ]; then
        : >"$3.body"
        while :; do
            line="$(tail -c +$((at + 1)) "$raw" | head -n 1 | tr -d '\r')"
            [[ "$line" =~ ^[0-9a-fA-F]+$ ]] || die "$3: not a chunk size at byte $at: '$line'"
            at=$((at + ${#line} + 2))
            chunk=$((16#$line))
            [ "$chunk" -eq 0 ] && break
            tail -c +$((at + 1)) "$raw" | head -c "$chunk" >>"$3.body"
            at=$((at + chunk + 2))
        done
        at=$((at + 2))
    else
        die "$3: a response without Content-Length nor chunked body"
    fi
    echo "$at"
}

# pipelined <stack> <base> <path 1> <file 1> <path 2> <file 2>: two GETs
# that PHP answers, sent at once on one keep-alive connection. Each
# response must be framed exactly: the first one ends where the second
# one starts, and nothing follows the second (no split, no merge).
pipelined() {
    local out="$workdir/$1/pipelined" port="${2##*:}" at request
    request="GET %s HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nX-Forwarded-Proto: https\r\nAccept-Encoding: identity\r\n%b\r\n"
    exec 3<>"/dev/tcp/127.0.0.1/$port"
    # shellcheck disable=SC2059
    printf "$request$request" "$3" "" "$5" 'Connection: close\r\n' >&3
    timeout 10 cat <&3 >"$out.raw" || true
    exec 3<&-
    at="$(http_response "$out.raw" 0 "$out.1")"
    at="$(http_response "$out.raw" "$at" "$out.2")"
    expect "$1: pipelined, first status" "$(head -n 1 "$out.1.headers" | cut -d' ' -f2)" 200
    cmp -s "$out.1.body" "$4" || die "$1: pipelined, the first body differs from $4"
    expect "$1: pipelined, second status" "$(head -n 1 "$out.2.headers" | cut -d' ' -f2)" 200
    cmp -s "$out.2.body" "$6" || die "$1: pipelined, the second body differs from $6"
    expect "$1: pipelined, nothing after the second response" "$(wc -c <"$out.raw" | tr -d ' ')" "$at"
}

smoke() {
    local s="$1" base web_log mark app css svg redirect size css_size
    base="http://127.0.0.1:$(port_of "$s")"
    web_log="$workdir/$s/logs/web-access.log"
    app="$workdir/$s/app/public/_cache/127.0.0.1"
    log "Smoke test: $s + PHP-FPM $php_version"
    origin_reset

    # Both redirect to the host without the port of the plain-HTTP request.
    redirect="$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' "$base$asset")"
    case "$redirect" in
        "301 https://127.0.0.1$asset") pass "$s: plain HTTP is redirected to https, without the port" ;;
        *) die "$s: plain HTTP is redirected to https: got '$redirect'" ;;
    esac
    expect "$s: X-Forwarded-Proto of an untrusted client is ignored" "$(untrusted_https "$s")" 301
    # The names of the project directories are paths like any other: the
    # document root is public/, where none of them exists.
    expect "$s: /vendor/ of the origin is proxied" "$(fetch "$base/vendor/livewire/livewire.js" "$workdir/$s/vendor")" 200
    cmp -s "$workdir/$s/vendor.body" "$root/tests/Fixtures/origin/vendor/livewire/livewire.js" ||
        die "$s: /vendor/livewire/livewire.js differs from the origin file"
    expect "$s: /vendor/ of the origin is cached" "$(fetch "$base/vendor/livewire/livewire.js" "$workdir/$s/vendor2")" 200
    expect "$s: /vendor/ hit" "$(cache_status "$workdir/$s/vendor2.headers")" HIT
    expect "$s: dot files stay refused" "$(status_of -H 'X-Forwarded-Proto: https' "$base/.git/config")" 403

    mark="$(request_mark)"
    expect "$s: first request" "$(fetch "$base$asset" "$workdir/$s/first")" 200
    cmp -s "$workdir/$s/first.body" "$fixture" || die "$s: the first response differs from the origin file"
    expect "$s: first request fetched from the origin" "$(origin_count "$asset")" 1
    via_php "$s: first request went through PHP-FPM" "$mark"
    [ -f "$app$asset" ] || die "$s: no cache entry at public/_cache/127.0.0.1$asset"
    pass "$s: cache entry written to public/_cache/127.0.0.1$asset"

    mark="$(request_mark)"
    expect "$s: second request" "$(fetch "$base$asset" "$workdir/$s/second")" 200
    cmp -s "$workdir/$s/second.body" "$fixture" || die "$s: the second response differs from the origin file"
    expect "$s: second request served without the origin" "$(origin_count "$asset")" 1
    web_only "$s: second request served" "$mark"

    cors_once "$s: miss" "$workdir/$s/first.headers" Access-Control-Allow-Origin X-Cache-Status Cache-Control
    hit_headers "$s: hit" "$workdir/$s/second.headers"
    expect "$s: the first request is a miss" "$(cache_status "$workdir/$s/first.headers")" MISS
    expect "$s: the second request is a hit" "$(cache_status "$workdir/$s/second.headers")" HIT
    varies_on "$s: a hit varies on Accept" "$workdir/$s/second.headers" Accept

    # Variants: the web server chooses them from Accept. Conversion is off
    # in the harness, so the test writes them itself.
    mkdir -p "$app/.webp/images" "$app/.avif/images"
    printf 'webp variant' >"$app/.webp$asset.webp"
    mark="$(request_mark)"
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
    web_only "$s: variants served" "$mark"

    # A legacy "X.webp" URL of 2.x is a hit PHP serves, by design (see
    # LEGACY_WEBP_URLS in the README): the WEBP variant of the raster
    # entry, without fetching anything from the origin.
    legacy="${asset%.*}.webp"
    mark="$(request_mark)"
    expect "$s: legacy WEBP URL" "$(fetch "$base$legacy" "$workdir/$s/legacy")" 200
    expect "$s: legacy WEBP URL body" "$(cat "$workdir/$s/legacy.body")" "webp variant"
    expect "$s: legacy WEBP URL type" "$(header_of Content-Type "$workdir/$s/legacy.headers")" image/webp
    expect "$s: legacy WEBP URL is a hit" "$(cache_status "$workdir/$s/legacy.headers")" HIT
    via_php "$s: legacy WEBP URL went through PHP-FPM" "$mark"
    expect "$s: legacy WEBP URL fetched nothing" "$(origin_count "$legacy")" 0
    expect "$s: legacy WEBP URL fetched no raster" "$(origin_count "$asset")" 1

    # Query string: the canonical one is a directory the web server finds.
    expect "$s: query string, first request" "$(fetch "$base$asset?v=3" "$workdir/$s/query1")" 200
    expect "$s: query string, first request is a miss" "$(cache_status "$workdir/$s/query1.headers")" MISS
    [ -f "$app/.q/v=3$asset" ] || die "$s: no cache entry at .q/v=3$asset"
    mark="$(request_mark)"
    expect "$s: query string, second request" "$(fetch "$base$asset?v=3" "$workdir/$s/query2")" 200
    expect "$s: query string, second request is a hit" "$(cache_status "$workdir/$s/query2.headers")" HIT
    web_only "$s: query string hit served" "$mark"
    expect "$s: query string fetched from the origin once" "$(origin_count "$asset")" 2

    # A hit that PHP must serve: "zz" is not in the allowlist, so the web
    # server cannot map the query string. Apache gets the file streamed by
    # PHP (no mod_xsendfile), nginx through X-Accel-Redirect: the headers
    # once either way, Cache-Control included (nginx passes on the one of
    # PHP and must not add its own).
    mark="$(request_mark)"
    expect "$s: PHP-path hit" "$(fetch "$base$asset?v=3&zz=1" "$workdir/$s/phphit")" 200
    cmp -s "$workdir/$s/phphit.body" "$fixture" || die "$s: the PHP-path hit differs from the origin file"
    expect "$s: PHP-path hit is a hit" "$(cache_status "$workdir/$s/phphit.headers")" HIT
    via_php "$s: PHP-path hit went through PHP-FPM" "$mark"
    hit_headers "$s: PHP-path hit" "$workdir/$s/phphit.headers"
    # nginx passes on the Content-Length of PHP, which is the length of the
    # file it measured. Apache drops it (CVE-2024-24795, public/.htaccess)
    # and frames the body itself: a counted length or chunked, and no
    # length on a HEAD.
    size="$(wc -c <"$fixture" | tr -d ' ')"
    css_size="$(wc -c <"$root/tests/Fixtures/origin/css/site.css" | tr -d ' ')"
    php_length "$s: PHP-path hit" "$workdir/$s/phphit.headers" "$size" "$s"
    expect "$s: HEAD of a PHP-path hit" "$(fetch "$base$asset?v=3&zz=1" "$workdir/$s/phphead" -I)" 200
    php_length "$s: HEAD of a PHP-path hit" "$workdir/$s/phphead.headers" "$size" "$s" head
    expect "$s: HEAD of a miss" "$(fetch "$base/css/site.css?v=9&zz=2" "$workdir/$s/headmiss" -I)" 200
    expect "$s: HEAD of a miss is a miss" "$(cache_status "$workdir/$s/headmiss.headers")" MISS
    php_length "$s: HEAD of a miss" "$workdir/$s/headmiss.headers" "$css_size" "$s" head
    pipelined "$s" "$base" "$asset?v=3&zz=1" "$fixture" "/css/site.css?v=9&zz=2" "$root/tests/Fixtures/origin/css/site.css"
    expect "$s: PHP-path hit has the Cache-Control of a web-server hit" \
        "$(cache_directives "$workdir/$s/phphit.headers")" "$(cache_directives "$workdir/$s/second.headers")"

    # Encodings: the proxy stores a gzip encoding of a text file.
    css=/css/site.css
    expect "$s: stylesheet, first request" "$(fetch "$base$css" "$workdir/$s/css1")" 200
    [ -f "$app/.gz$css" ] || die "$s: no gzip encoding at .gz$css"
    mark="$(request_mark)"
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
    web_only "$s: encodings served" "$mark"

    # SVG: the policy of PHP, once.
    svg=/images/logo.svg
    expect "$s: SVG, first request" "$(fetch "$base$svg" "$workdir/$s/svg1")" 200
    mark="$(request_mark)"
    expect "$s: SVG, second request" "$(fetch "$base$svg" "$workdir/$s/svg2")" 200
    web_only "$s: SVG hit served" "$mark"
    cors_once "$s: SVG miss" "$workdir/$s/svg1.headers" Content-Security-Policy
    cors_once "$s: SVG hit" "$workdir/$s/svg2.headers" Content-Security-Policy
    expect "$s: SVG hit policy" "$(header_of Content-Security-Policy "$workdir/$s/svg2.headers")" \
        "$(header_of Content-Security-Policy "$workdir/$s/svg1.headers")"

    # What must reach PHP even for a cached file.
    mark="$(request_mark)"
    expect "$s: PURGE of a cached file reaches PHP" \
        "$(status_of -X PURGE -H 'X-Forwarded-Proto: https' "$base$asset")" 401
    expect "$s: preflight of a cached file" "$(preflight "$base$asset" "$workdir/$s/options-hit")" 204
    cors_once "$s: preflight of a cached file" "$workdir/$s/options-hit.headers" \
        Access-Control-Allow-Origin Access-Control-Allow-Methods Access-Control-Max-Age
    expect "$s: unlisted host" "$(status_of -H 'X-Forwarded-Proto: https' -H 'Host: other.example' "$base$asset")" 421
    via_php "$s: PURGE, OPTIONS and the unlisted host reached PHP-FPM" "$mark"
    expect "$s: preflight of a missing file" "$(preflight "$base/images/missing.png" "$workdir/$s/options-miss")" 204
    cors_once "$s: preflight of a missing file" "$workdir/$s/options-miss.headers" \
        Access-Control-Allow-Origin Access-Control-Allow-Methods Access-Control-Max-Age
    expect "$s: missing file" "$(fetch "$base/images/missing.png" "$workdir/$s/missing")" 404

    # A body above post_max_size (1K in the harness, whose php.ini displays
    # errors): PHP warns before the proxy runs. The web server
    # configurations force display_errors off, so the warning stays out of
    # the body and the 405 of the proxy stands.
    head -c 4000 /dev/zero | tr '\0' a >"$workdir/$s/post.data"
    expect "$s: POST above post_max_size" \
        "$(fetch "$base/images/post.png" "$workdir/$s/post" -X POST --data-binary "@$workdir/$s/post.data")" 405
    if grep -qi 'warning\|post_max_size\|content-length of' "$workdir/$s/post.body"; then
        die "$s: a PHP warning reached the body: $(head -c 200 "$workdir/$s/post.body")"
    fi
    pass "$s: POST above post_max_size: no PHP warning in the body"
    case "$(header_of Content-Type "$workdir/$s/post.headers")" in
        text/html*) die "$s: POST above post_max_size answered text/html" ;;
    esac
    pass "$s: POST above post_max_size: not text/html"
    expect "$s: an error carries no cache status" "$(cache_status "$workdir/$s/missing.headers")" ""
    case "$(status_of -H 'X-Forwarded-Proto: https' "$base/_cache/127.0.0.1$asset")" in
        200) die "$s: the cache directory is readable directly" ;;
        *) pass "$s: the cache directory is not readable directly" ;;
    esac
}

# burst <stack>: 500 concurrent requests for one cold entry, whose origin
# answers after a second, cause exactly one origin fetch (request
# coalescing, PRX-7), and every request gets the asset.
burst() {
    local s="$1" base path=/js/app.js config codes half web_log lines php
    base="http://127.0.0.1:$(port_of "$s")"
    web_log="$workdir/$s/logs/web-access.log"
    log "Coalescing: $s + PHP-FPM $php_version, $burst_size concurrent requests on one cold entry"
    origin_reset
    curl -fsS -X PUT "$origin/__origin/rules?path=$path&delay=1000" >/dev/null
    # curl runs at most 300 transfers in parallel: two halves, side by side.
    for half in 1 2; do
        config="$workdir/$s/burst-$half.curlrc"
        : >"$config"
        for _ in $(seq $((burst_size / 2))); do
            printf 'url = "%s"\noutput = "/dev/null"\n' "$base$path" >>"$config"
        done
    done
    lines="$(lines_of "$web_log")"
    for half in 1 2; do
        curl -sS --parallel --parallel-immediate --parallel-max $((burst_size / 2)) -H 'X-Forwarded-Proto: https' \
            -w '%{http_code}\n' --config "$workdir/$s/burst-$half.curlrc" >"$workdir/$s/burst-$half.codes" &
    done
    wait
    codes="$(cat "$workdir/$s"/burst-*.codes | sort | uniq -c | sed 's/^ *//')"
    expect "$s: every request of the burst got the asset" "$codes" "$burst_size 200"
    expect "$s: the burst caused one origin fetch" "$(origin_count "$path")" 1
    wait_for "$s: the web server logged the burst" 30 has_lines "$web_log" $((lines + burst_size))
    php="$(tail -n +$((lines + 1)) "$web_log" | cut -d' ' -f2 | grep -c -E '^proxy|:[0-9]' || true)"
    pass "$s: $php of the $burst_size requests reached PHP-FPM, which waited for one fetch"
    curl -fsS -X DELETE "$origin/__origin/rules?path=$path" >/dev/null
    expect "$s: the burst left a cache entry" "$(fetch "$base$path" "$workdir/$s/burst")" 200
    expect "$s: the entry of the burst is a hit" "$(cache_status "$workdir/$s/burst.headers")" HIT
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

# resize_first <stack>: a signed resize URL is the first request of an
# empty cache, so that the work directory of the conversions creates
# public/_cache/. Only that directory may be private: the web server,
# which runs as another user than PHP-FPM, must still enter the cache to
# serve the hits. Conversion is off in the harness: the step turns the
# local converter and the resize URLs on for its request only (the
# official images have no backend, so the image is not resized).
resize_first() {
    local s="$1" base env="$workdir/$1/app/.env" cache="$workdir/$1/app/public/_cache" path status
    base="http://127.0.0.1:$(port_of "$s")"
    log "Resize first: $s"
    [ ! -e "$cache" ] || die "$s: the cache exists before the first request"
    cp "$env" "$env.saved"
    sed -i 's/^IMAGE_CONVERTER = .*/IMAGE_CONVERTER = "local"/' "$env"
    printf 'RESIZE_WIDTHS = "8"\nRESIZE_KEY = "harness-resize-key-0123456789abcdef"\n' >>"$env"
    path="$(compose exec -T "fpm-$s" php -r '
        require "/app/vendor/autoload.php";
        echo (new Mfonte\ProxyCdn\Image\SignedUrlVerifier($argv[1], [8]))->pathOf("127.0.0.1", 8, $argv[2], "");' \
        harness-resize-key-0123456789abcdef "$asset")"
    status="$(fetch "$base$path" "$workdir/$s/resize")"
    mv "$env.saved" "$env"
    expect "$s: the resize URL is answered" "$status" 200
    expect "$s: public/_cache stays enterable" "$(stat -c %a "$cache")" 755
    expect "$s: the work directory is private" "$(stat -c %a "$cache/.work")" 700
}

burst_size=500
for s in "${stacks[@]}"; do
    client "$s"
    resize_first "$s"
    smoke "$s"
    burst "$s"
done

log "Integration smoke test passed on PHP $php_version (${stacks[*]})"
