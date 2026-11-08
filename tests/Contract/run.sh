#!/usr/bin/env bash
#
# Contract test of the proxy against the real conversion API v2: the API's
# hardened image (its docker/Dockerfile) runs behind nginx with the API's
# own docker/compose.yaml and deploy/nginx configuration, over TLS for
# images-api.example.com; then tests/Contract (PHPUnit, the "contract"
# suite) runs in a php:<version>-cli container on the host network, where
# RemoteConverter, VariantConverter and ProxyKernel call the API.
#
# Usage: tests/Contract/run.sh --api <checkout of the API> [--php <version>] [--keep]
#
#   --api   a checkout of mauriziofonte/php-image-to-webp-conversion-api
#           (the CI checks it out at a pinned commit)
#   --php   PHP version of the PHPUnit run (default 8.5)
#   --keep  leave the containers and CONTRACT_WORKDIR in place afterwards
#
# vendor/ of the proxy is mounted into the PHPUnit container: install it
# beforehand, with the dev dependencies, for a version that runs on --php.
#
# Environment:
#   CONTRACT_IMAGE_MIRROR  registry the official images are pulled from when
#                          their Docker Hub name is not present locally
#                          (default mirror.gcr.io/library; empty for Docker
#                          Hub). Nothing is tagged.
#   CONTRACT_WORKDIR       empty directory for the certificate and the logs
#                          (default: a temporary directory, removed at the
#                          end unless --keep)
#   CONTRACT_API_IMAGE     an image of the API's docker/Dockerfile to run
#                          instead of building one (it is not removed)
#   CONTRACT_API_VENDOR    build with this vendor/ of the API (PHP 8.3, no
#                          dev) as the "app-vendor" context instead of running
#                          Composer in the build
#   COMPOSER_AUTH          handed to Composer in the build as the
#                          "composer_auth" secret (a GitHub token)

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../.." && pwd)"

php_version=8.5
api=""
keep=0

log() { printf '==> %s\n' "$*"; }
pass() { printf '    ok: %s\n' "$*"; }
die() {
    printf 'FAILED: %s\n' "$*" >&2
    exit 1
}
usage() { sed -n '10,15p' "$0" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
    case "$1" in
        --api) api="${2:-}"; shift 2 || die "--api needs a directory" ;;
        --api=*) api="${1#*=}"; shift ;;
        --php) php_version="${2:-}"; shift 2 || die "--php needs a version" ;;
        --php=*) php_version="${1#*=}"; shift ;;
        --keep) keep=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; die "unknown argument: $1" ;;
    esac
done

[[ "$php_version" =~ ^[0-9]+\.[0-9]+$ ]] || die "--php expects a version such as 8.5, got '$php_version'"
[ -n "$api" ] || { usage >&2; die "--api is required"; }
api="$(cd "$api" && pwd)" || die "--api: no such directory"
[ -f "$api/docker/Dockerfile" ] && [ -f "$api/docker/compose.yaml" ] && [ -f "$api/deploy/nginx/image-conversion-api.conf" ] ||
    die "$api is not a checkout of the conversion API v2 (docker/Dockerfile, docker/compose.yaml, deploy/nginx)"
[ -f "$root/vendor/autoload.php" ] || die "vendor/ is missing: install the dependencies of the proxy first"
for tool in docker openssl curl sha256sum; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is required"
done

own_workdir=0
if [ -n "${CONTRACT_WORKDIR:-}" ]; then
    mkdir -p "$CONTRACT_WORKDIR"
    workdir="$(cd "$CONTRACT_WORKDIR" && pwd)"
else
    workdir="$(mktemp -d "${TMPDIR:-/tmp}/proxy-contract.XXXXXX")"
    own_workdir=1
fi
chmod 755 "$workdir"

suffix="php${php_version//./}-$$"
project="proxy-contract-$suffix"
server_name=images-api.example.com
api_key="contract-api-key-$(openssl rand -hex 16)"
request_id="proxy-contract-$(openssl rand -hex 8)"
built_image=""
started=0

# PHPUnit runs as the caller, so that the caller can remove what it writes;
# as an unprivileged user when the caller is root.
if [ "$(id -u)" -eq 0 ]; then
    run_user=65534:65534
else
    run_user="$(id -u):$(id -g)"
fi

compose() { docker compose --project-name "$project" --file "$api/docker/compose.yaml" --file "$workdir/images.yaml" "$@"; }

cleanup() {
    local status=$?
    set +e
    if [ "$started" -eq 1 ]; then
        compose logs --no-color --timestamps >"$workdir/compose.log" 2>&1
        if [ "$status" -ne 0 ]; then
            log "Diagnostics"
            compose ps --all
            tail -n 80 "$workdir/compose.log"
        fi
        [ "$keep" -eq 1 ] || compose down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1
    fi
    if [ -n "$built_image" ] && [ "$keep" -eq 0 ]; then
        docker image rm "$built_image" >/dev/null 2>&1
    fi
    if [ "$keep" -eq 1 ]; then
        log "Kept $workdir and the project $project. Remove them with:"
        printf '    docker compose --project-name %s down --volumes && rm -rf %s\n' "$project" "$workdir"
    elif [ "$own_workdir" -eq 1 ]; then
        rm -rf "$workdir"
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

mirror="${CONTRACT_IMAGE_MIRROR-mirror.gcr.io/library}"
# resolve <image>: the local name to run <image> by: <image> itself when it
# is present, otherwise its name on the mirror, pulled when missing.
resolve() {
    local image="$1" source_image
    if docker image inspect "$image" >/dev/null 2>&1; then
        printf '%s\n' "$image"
        return
    fi
    source_image="${mirror:+$mirror/}$image"
    if ! docker image inspect "$source_image" >/dev/null 2>&1; then
        docker pull --quiet "$source_image" >/dev/null || die "cannot pull $source_image"
    fi
    printf '%s\n' "$source_image"
}

wait_for() {
    local what="$1" seconds="$2"
    shift 2
    local deadline=$((SECONDS + seconds))
    until "$@" >/dev/null 2>&1; do
        [ "$SECONDS" -lt "$deadline" ] || die "timed out waiting for: $what"
        sleep 1
    done
    pass "$what"
}

# --- the API ----------------------------------------------------------------

log "Certificate of $server_name"
mkdir -p "$workdir/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$server_name" \
    -addext "subjectAltName=DNS:$server_name" \
    -keyout "$workdir/tls/$server_name.key" -out "$workdir/tls/$server_name.pem" >/dev/null 2>&1 ||
    die "openssl cannot make the certificate"
chmod 644 "$workdir/tls/$server_name.key" "$workdir/tls/$server_name.pem"
pass "self-signed, trusted by the proxy through IMAGE_API_CA_FILE"

log "Image of the API"
if [ -n "${CONTRACT_API_IMAGE:-}" ]; then
    image="$CONTRACT_API_IMAGE"
    docker image inspect "$image" >/dev/null 2>&1 || die "CONTRACT_API_IMAGE $image does not exist"
    pass "$image (given)"
else
    image="proxy-contract/image-conversion-api:$suffix"
    ubuntu="$(resolve ubuntu:24.04)" || exit 1
    build=(docker build --network host --file "$api/docker/Dockerfile" --tag "$image" --build-arg "UBUNTU_IMAGE=$ubuntu")
    if [ -n "${CONTRACT_API_VENDOR:-}" ]; then
        build+=(--build-arg VENDOR_SOURCE=context --build-context "app-vendor=$CONTRACT_API_VENDOR")
    else
        composer_image="$(sed -n 's/^ARG COMPOSER_IMAGE=//p' "$api/docker/Dockerfile")"
        [ -n "$composer_image" ] || die "docker/Dockerfile of the API names no COMPOSER_IMAGE"
        composer_image="$(resolve "$composer_image")" || exit 1
        build+=(--build-arg "COMPOSER_IMAGE=$composer_image")
        if [ -n "${COMPOSER_AUTH:-}" ]; then
            build+=(--secret id=composer_auth,env=COMPOSER_AUTH)
        fi
    fi
    built_image="$image"
    log "Building $image (on $ubuntu)"
    "${build[@]}" "$api" >"$workdir/build.log" 2>&1 || { tail -n 60 "$workdir/build.log"; die "docker build failed"; }
    pass "built $image"
fi

# The keys as their digests, as the API recommends; no rate limit.
export API_KEYS="sha256:$(printf '%s' "$api_key" | sha256sum | cut -d ' ' -f 1)" \
    METRICS_KEYS="" V1_SCRIPT_NAME="" RATE_LIMIT=0 TLS_DIR="$workdir/tls" \
    HTTPS_PUBLISH="127.0.0.1:" HTTP_PUBLISH="127.0.0.1:" IMAGE_CONVERSION_API_IMAGE="$image"
nginx_image="$(sed -n 's/^ *image: *\(nginx:[^ ]*\) *$/\1/p' "$api/docker/compose.yaml" | head -n 1)"
[ -n "$nginx_image" ] || die "docker/compose.yaml of the API names no nginx image"
nginx_image="$(resolve "$nginx_image")" || exit 1
printf 'services:\n  app:\n    image: %s\n  web:\n    image: %s\n' "$image" "$nginx_image" >"$workdir/images.yaml"

log "Starting the API behind nginx (docker/compose.yaml of the API)"
started=1
compose up --detach --no-build --pull never >"$workdir/up.log" 2>&1 || { cat "$workdir/up.log"; die "docker compose up failed"; }
port_of() { compose port "$1" "$2" | sed -n 's/.*:\([0-9][0-9]*\)$/\1/p' | head -n 1; }
https_port="$(port_of web 443)"
http_port="$(port_of web 80)"
[ -n "$https_port" ] && [ -n "$http_port" ] || die "nginx publishes no port"
curl_tls=(--noproxy '*' --silent --cacert "$workdir/tls/$server_name.pem" --resolve "$server_name:$https_port:127.0.0.1")
healthz_ok() { curl "${curl_tls[@]}" "https://$server_name:$https_port/healthz" | grep -q '"status":"ok"'; }
wait_for "the API answers /healthz over TLS" 90 healthz_ok
# The first /healthz with a key probes the backends and the isolation of
# the API, for seconds: done once here, as a deployment would warm it.
capabilities_ok() { curl "${curl_tls[@]}" -H "Authorization: Bearer $api_key" "https://$server_name:$https_port/healthz" | grep -q '"capabilities"'; }
wait_for "the API reports its capabilities to a key" 120 capabilities_ok

# --- the proxy --------------------------------------------------------------

log "Contract tests (PHP $php_version, as $run_user)"
php_image="$(resolve "php:$php_version-cli")" || exit 1
docker run --rm --network host --user "$run_user" -e HOME=/tmp \
    --add-host "$server_name:127.0.0.1" \
    -v "$root:$root:ro" -v "$workdir/tls:$workdir/tls:ro" -w "$root" \
    -e "CONTRACT_API_URL=https://$server_name:$https_port" \
    -e "CONTRACT_API_HTTP_URL=http://$server_name:$http_port" \
    -e "CONTRACT_API_KEY=$api_key" \
    -e "CONTRACT_API_CA_FILE=$workdir/tls/$server_name.pem" \
    -e "CONTRACT_REQUEST_ID=$request_id" \
    "$php_image" php vendor/bin/phpunit --testsuite contract ||
    die "the contract tests failed"
pass "contract tests"

log "The API logged the request ID the proxy sent"
sleep 1
compose logs --no-color app >"$workdir/app.log" 2>&1
grep -q "\"request_id\":\"$request_id\"" "$workdir/app.log" || die "no log line of the API carries $request_id"
pass "X-Request-Id $request_id reached the API"

log "Contract with the conversion API passed (PHP $php_version)"
