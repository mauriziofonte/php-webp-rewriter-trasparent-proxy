#!/usr/bin/env bash
#
# Embedding check: creates a fresh Laravel application, installs this
# checkout into it through a Composer path repository (plus the VCS
# repository of mfonte/secure-image-converter, since only the root package's
# repositories are honoured), mounts ProxyKernel::handle() on a catch-all
# route and checks over HTTP that an asset of a fake origin is served, then
# served again from the query-string cache. The install itself is the check
# that the proxy resolves next to the framework without Composer conflicts.
#
# Usage: tests/Integration/laravel/run.sh [--keep]
#
#   --keep    leave LARAVEL_WORKDIR in place afterwards
#
# Environment:
#   LARAVEL_CONSTRAINT  constraint of laravel/laravel (default ^13.0)
#   LARAVEL_WORKDIR     empty directory for the application and the logs
#                       (default: a temporary directory, removed at the end
#                       unless --keep)

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../../.." && pwd)"
fixtures="$root/tests/Fixtures/origin"
asset=/images/gradient.png
constraint="${LARAVEL_CONSTRAINT:-^13.0}"
keep=0

log() { printf '==> %s\n' "$*"; }
pass() { printf '    ok: %s\n' "$*"; }
die() {
    printf 'FAILED: %s\n' "$*" >&2
    exit 1
}

while [ $# -gt 0 ]; do
    case "$1" in
        --keep) keep=1; shift ;;
        -h | --help) sed -n '11,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

for tool in php composer curl cmp; do
    command -v "$tool" >/dev/null || die "$tool is required"
done

own_workdir=0
if [ -n "${LARAVEL_WORKDIR:-}" ]; then
    mkdir -p "$LARAVEL_WORKDIR"
    [ -z "$(ls -A "$LARAVEL_WORKDIR")" ] || die "LARAVEL_WORKDIR must be empty: $LARAVEL_WORKDIR"
    workdir="$(cd "$LARAVEL_WORKDIR" && pwd)"
else
    workdir="$(mktemp -d "${TMPDIR:-/tmp}/proxycdn-laravel.XXXXXX")"
    own_workdir=1
fi
app="$workdir/app"
pids=()

cleanup() {
    local status=$?
    for pid in "${pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    if [ "$status" -ne 0 ]; then
        for file in "$workdir"/*.log; do
            [ -f "$file" ] && { printf -- '--- %s\n' "$file"; tail -n 40 "$file"; }
        done
        [ -f "$app/storage/logs/laravel.log" ] && { printf -- '--- laravel.log\n'; tail -n 40 "$app/storage/logs/laravel.log"; }
    fi
    if [ "$keep" -eq 0 ] && [ "$own_workdir" -eq 1 ]; then
        rm -rf "$workdir"
    fi
    exit "$status"
}
trap cleanup EXIT

free_port() {
    php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);'
}

wait_for() {
    local url="$1" i
    for i in $(seq 1 100); do
        curl -s -o /dev/null "$url" && return 0
        sleep 0.2
    done
    die "nothing answers on $url"
}

# --- what to install, read from this checkout's composer.json ---------------

read_manifest() {
    php -r '
        $manifest = json_decode((string) file_get_contents($argv[1]), true);
        if ($argv[2] === "constraint") {
            echo $manifest["require"]["mfonte/secure-image-converter"];
        } else {
            foreach ($manifest["repositories"] as $repository) {
                if (strpos($repository["url"], "secure-image-converter") !== false) {
                    echo $repository["url"];
                }
            }
        }
    ' "$root/composer.json" "$1"
}
library_constraint="$(read_manifest constraint)"
library_url="$(read_manifest url)"
[ -n "$library_constraint" ] && [ -n "$library_url" ] || die "cannot read the library requirement from composer.json"

# --- application ------------------------------------------------------------

log "creating laravel/laravel:$constraint"
composer create-project "laravel/laravel:$constraint" "$app" --no-interaction --no-progress >"$workdir/create-project.log" 2>&1 \
    || die "composer create-project failed (see create-project.log)"
pass "$(cd "$app" && composer show laravel/framework --no-interaction | sed -n 's/^versions *: *//p' | head -n 1 | sed 's/^/laravel\/framework /')"

log "requiring mfonte/proxy-cdn from $root"
(
    cd "$app"
    composer config repositories.proxy-cdn "{\"type\": \"path\", \"url\": \"$root\", \"options\": {\"symlink\": true}}"
    composer config repositories.secure-image-converter vcs "$library_url"
    composer require "mfonte/secure-image-converter:$library_constraint" "mfonte/proxy-cdn:*@dev" \
        --no-interaction --no-progress --update-with-all-dependencies
) >"$workdir/require.log" 2>&1 || die "composer require failed: the proxy does not install next to Laravel (see require.log)"
for package in mfonte/proxy-cdn mfonte/secure-image-converter symfony/http-foundation symfony/console vlucas/phpdotenv psr/log; do
    pass "$(cd "$app" && composer show "$package" --no-interaction | sed -n 's/^versions *: *//p' | head -n 1 | sed "s|^|$package |")"
done

# Sessions in memory: the default database driver would need the migrations
# that create-project runs, and the check does not depend on them.
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=array/' "$app/.env"

origin_port="$(free_port)"
app_port="$(free_port)"
origin_url="http://127.0.0.1:$origin_port"

# The route builds the object graph as public/index.php does, from values
# the host holds itself, and uses Laravel's PSR-3 logger.
cat >>"$app/routes/web.php" <<PHP

Route::get('/{path}', function (\Illuminate\Http\Request \$request) {
    \$config = (new \Mfonte\ProxyCdn\Config\ConfigLoader(null))->loadFromArray([
        'SOURCE_ORIGIN' => '$origin_url',
        'PROXY_HOSTS' => '127.0.0.1',
        'CACHEABLE_EXTENSIONS' => 'css,js,png,jpg',
        'UPSTREAM_VERIFY_TLS' => 'true',
        'IMAGE_CONVERSION_MODE' => 'none',
    ]);
    \$logger = app('log');
    \$kernel = \Mfonte\ProxyCdn\Http\ProxyKernelFactory::create(
        \$config,
        storage_path('proxy-cdn'),
        storage_path('proxy-cdn-state'),
        \Mfonte\ProxyCdn\Origin\HttpOriginFetcher::fromConfig(\$config, \$logger),
        null,
        \$logger
    );

    return \$kernel->handle(\$request);
})->where('path', '.*');
PHP
php -l "$app/routes/web.php" >/dev/null || die "routes/web.php does not compile"

# --- servers ----------------------------------------------------------------

log "starting the fake origin on $origin_url"
mkdir -p "$workdir/origin-state"
FAKE_ORIGIN_STATE="$workdir/origin-state" FAKE_ORIGIN_DOCROOT="$fixtures" \
    php -S "127.0.0.1:$origin_port" -t "$fixtures" "$root/tests/Fixtures/FakeOrigin/router.php" >"$workdir/origin.log" 2>&1 &
pids+=($!)
wait_for "$origin_url/css/site.css"

log "starting Laravel on 127.0.0.1:$app_port"
(cd "$app" && exec php artisan serve --host=127.0.0.1 --port="$app_port" --no-reload) >"$workdir/laravel.log" 2>&1 &
pids+=($!)
wait_for "http://127.0.0.1:$app_port/up"

# --- checks -------------------------------------------------------------------

fetch() {
    curl -sS -o "$workdir/body" -D "$workdir/headers" -w '%{http_code}' "http://127.0.0.1:$app_port$1"
}
header() {
    tr -d '\r' <"$workdir/headers" | sed -n "s/^$1: *//Ip" | tail -n 1
}
# Number of requests the fake origin received for a path.
origin_requests() {
    php -r '
        $count = 0;
        foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $request = json_decode($line, true);
            $count += is_array($request) && ($request["path"] ?? null) === $argv[2] ? 1 : 0;
        }
        echo $count;
    ' "$workdir/origin-state/requests.jsonl" "$1"
}

log "GET $asset through the Laravel route"
status="$(fetch "$asset")"
[ "$status" = 200 ] || die "status $status for $asset"
cmp -s "$workdir/body" "$fixtures$asset" || die "the body differs from the origin file"
[ "$(header x-cache-status)" = MISS ] || die "x-cache-status is '$(header x-cache-status)', expected MISS"
[ "$(header content-type)" = image/png ] || die "content-type is '$(header content-type)'"
[ "$(origin_requests "$asset")" = 1 ] || die "the fake origin was not asked for $asset"
cmp -s "$app/storage/proxy-cdn/_cache/127.0.0.1$asset" "$fixtures$asset" || die "the asset was not stored in storage/proxy-cdn/_cache/127.0.0.1"
pass "200, origin bytes, x-cache-status: MISS, stored under storage/proxy-cdn/_cache/127.0.0.1"

log "GET $asset?v=1 twice: the second answer comes from the cache"
fetch "$asset?v=1" >/dev/null
status="$(fetch "$asset?v=1")"
[ "$status" = 200 ] || die "status $status for $asset?v=1"
cmp -s "$workdir/body" "$fixtures$asset" || die "the cached body differs from the origin file"
[ "$(header x-cache-status)" = HIT ] || die "x-cache-status is '$(header x-cache-status)', expected HIT"
[ "$(origin_requests "$asset")" = 2 ] || die "the cache hit reached the origin"
pass "200, origin bytes, x-cache-status: HIT, origin not asked again"

log "GET /missing.png: the origin 404 is answered by the kernel"
status="$(fetch /missing.png)"
[ "$status" = 404 ] || die "status $status for /missing.png"
grep -q 'Error code: Cannot read remote origin file.' "$workdir/body" || die "unexpected body for /missing.png"
pass "404 with the proxy error body"

log "all checks passed"
