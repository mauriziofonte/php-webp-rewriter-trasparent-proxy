#!/usr/bin/env sh
# Prepares the example of compose.yaml: a private CA and a certificate for
# each node in tls/, the data directories of each node in data/, the
# runtime directories of the project that the nodes mount over, and .env
# (the user PHP runs as). Run it again to renew the certificates.
set -eu
cd "$(dirname "$0")"

uid=${CLUSTER_UID:-$(id -u)}
gid=${CLUSTER_GID:-$(id -g)}

mkdir -p tls ../../public/_cache ../../var ../../logs
for node in node-a node-b; do
    mkdir -p "data/$node/cache" "data/$node/var" "data/$node/logs"
done
# var/ holds lock files: the library refuses a directory others can write.
chmod -R go-w data
if [ "$(id -u)" = 0 ]; then
    chown -R "$uid:$gid" data
fi

openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=proxy-cdn example CA" \
    -keyout tls/ca.key -out tls/ca.pem 2>/dev/null
for node in node-a node-b; do
    openssl req -newkey rsa:2048 -nodes -subj "/CN=$node" \
        -keyout "tls/$node.key" -out "tls/$node.csr" 2>/dev/null
    printf 'subjectAltName=DNS:%s\nextendedKeyUsage=serverAuth\n' "$node" >"tls/$node.ext"
    openssl x509 -req -in "tls/$node.csr" -CA tls/ca.pem -CAkey tls/ca.key -CAcreateserial \
        -days 365 -extfile "tls/$node.ext" -out "tls/$node.pem" 2>/dev/null
    rm -f "tls/$node.csr" "tls/$node.ext"
done
rm -f tls/ca.key tls/ca.srl
# Read by the nginx master process, which runs as root.
chmod 0600 tls/*.key

printf 'CLUSTER_UID=%s\nCLUSTER_GID=%s\n' "$uid" "$gid" >.env
echo "Ready: docker compose up -d, then:"
echo "  curl -H 'Host: cdn.example.test' http://127.0.0.1:8080/site.css"
