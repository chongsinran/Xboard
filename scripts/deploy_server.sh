#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

warn() {
    echo "WARNING: $*" >&2
}

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

trap 'echo "ERROR: Deployment failed at line ${LINENO}. Review the message above and run: docker compose ps -a" >&2' ERR

if ! command -v docker >/dev/null 2>&1; then
    fail "Docker is not installed."
fi

if ! docker compose version >/dev/null 2>&1; then
    fail "Docker Compose is not available."
fi

echo "[1/10] Running deployment preflight..."
memory_kib="$(awk '/MemTotal:/ {print $2}' /proc/meminfo)"
if (( memory_kib < 1900000 )); then
    warn "This server has less than 2 GB RAM. Xboard, queue workers, Redis, and WebSockets may be OOM-killed."
fi

if [[ "$(sysctl -n vm.overcommit_memory 2>/dev/null || echo 0)" != "1" ]]; then
    warn "vm.overcommit_memory is not 1. Redis background saves may fail under memory pressure."
fi

echo "[2/10] Initializing Git submodules..."
git submodule sync --recursive
git submodule update --init --recursive

if [[ ! -f compose.yaml && ! -f compose.yml && ! -f docker-compose.yaml && ! -f docker-compose.yml ]]; then
    cp compose.sample.yaml compose.yaml
    echo "Created compose.yaml from compose.sample.yaml."
fi

if [[ ! -f .env ]]; then
    cp .env.example .env
    echo "Created .env from .env.example."
fi

# Compose requires the bind-mount source to exist even when Apple IAP is not
# configured yet. Replace this placeholder with the real App Store key later.
mkdir -p secrets
touch secrets/AuthKey.p8

compose_config="$(docker compose config)"
for required_service in web horizon ws-server redis; do
    if ! docker compose config --services | grep -qx "$required_service"; then
        fail "compose.yaml does not define the required service: ${required_service}"
    fi
done

if grep -q '/run/redis/redis.sock' <<<"$compose_config"; then
    redis_socket='/run/redis/redis.sock'
    legacy_redis_layout=false
else
    redis_socket='/data/redis.sock'
    legacy_redis_layout=true
    warn "Legacy Redis layout detected: application containers share /data with Redis persistence. Copy the current compose.sample.yaml to a reviewed compose.yaml to prevent recurring permission failures."
fi

# Match Laravel to the socket path used by the active Compose configuration.
sed -i \
    -e "s#^REDIS_HOST=.*#REDIS_HOST=${redis_socket}#" \
    -e 's#^REDIS_PORT=.*#REDIS_PORT=0#' \
    -e 's#^REDIS_PASSWORD=.*#REDIS_PASSWORD=null#' \
    .env

echo "[3/10] Pulling container images..."
docker compose pull

echo "[4/10] Repairing the Redis volumes and starting Redis..."
if [[ "$legacy_redis_layout" == true ]]; then
    docker compose run --rm --no-deps --user 0 --entrypoint sh redis \
        -c 'chown -R redis:redis /data && chmod 1777 /data'
else
    docker compose run --rm --no-deps --user 0 --entrypoint sh redis \
        -c 'chown -R redis:redis /data /run/redis && chmod 770 /data && chmod 1777 /run/redis'
fi
docker compose up -d redis
for attempt in {1..30}; do
    if docker compose exec -T redis redis-cli -s "$redis_socket" ping 2>/dev/null | grep -q PONG; then
        break
    fi
    if [[ "$attempt" == 30 ]]; then
        fail "Redis did not become ready on ${redis_socket}."
    fi
    sleep 1
done

echo "[5/10] Installing locked PHP dependencies..."
docker compose run --rm --entrypoint composer web \
    install --no-dev --prefer-dist --no-interaction --optimize-autoloader

echo "[6/10] Applying the admin bundle patch..."
docker compose run --rm --entrypoint php web scripts/patch_ios_admin_bundle.php
docker compose run --rm --entrypoint php web scripts/patch_free_node_admin_bundle.php
docker compose run --rm --entrypoint php web scripts/patch_user_panel_admin_bundle.php

if grep -q '^INSTALLED=true' .env; then
    echo "[7/10] Updating the existing Xboard installation..."
    docker compose run --rm web php artisan config:clear
    docker compose run --rm web php artisan xboard:update
else
    if [[ -z "${ADMIN_ACCOUNT:-}" ]]; then
        read -r -p "Admin email for the new installation: " ADMIN_ACCOUNT
    fi
    if [[ -z "$ADMIN_ACCOUNT" ]]; then
        echo "An admin email is required for a new installation." >&2
        exit 1
    fi

    echo "[7/10] Installing a fresh Xboard database..."
    docker compose run --rm \
        -e ENABLE_SQLITE=true \
        -e ENABLE_REDIS=true \
        -e ADMIN_ACCOUNT="$ADMIN_ACCOUNT" \
        web php artisan xboard:install
fi

echo "[8/10] Recreating Xboard services..."
docker compose up -d --force-recreate
docker compose run --rm web php artisan optimize:clear

if [[ "$legacy_redis_layout" == true ]]; then
    docker compose exec -T --user 0 web chmod 1777 /data
fi

echo "[9/10] Running service health checks..."
docker compose exec -T redis redis-cli -s "$redis_socket" SET xboard:deployment-health "$(date +%s)" >/dev/null
docker compose exec -T redis redis-cli -s "$redis_socket" BGSAVE SCHEDULE >/dev/null
sleep 2
if ! docker compose exec -T redis redis-cli -s "$redis_socket" INFO persistence |
    tr -d '\r' |
    grep -q '^rdb_last_bgsave_status:ok$'; then
    fail "Redis cannot persist snapshots. Check: docker compose logs --tail=100 redis"
fi

for attempt in {1..30}; do
    if docker compose exec -T web php -r \
        "exit(@file_get_contents('http://127.0.0.1:7001/api/v1/guest/comm/config') === false ? 1 : 0);" 2>/dev/null; then
        break
    fi
    if [[ "$attempt" == 30 ]]; then
        fail "Xboard HTTP health endpoint did not become ready."
    fi
    sleep 2
done

if ! docker compose exec -T web php -r \
    '$socket = @fsockopen("127.0.0.1", 8076, $errno, $error, 3); exit($socket === false ? 1 : 0);'; then
    fail "The node WebSocket service is not accepting connections on port 8076."
fi

if docker compose logs --since=5m horizon 2>&1 | grep -q 'stop-when-empty-for.*does not exist'; then
    fail "The Horizon compatibility loop is active. Use the queue:work service definition from compose.sample.yaml."
fi

for required_service in web horizon ws-server redis; do
    container_id="$(docker compose ps -q "$required_service")"
    if [[ -z "$container_id" || "$(docker inspect -f '{{.State.Running}}' "$container_id")" != "true" ]]; then
        fail "Service ${required_service} is not running."
    fi
    if [[ "$(docker inspect -f '{{.State.OOMKilled}}' "$container_id")" == "true" ]]; then
        fail "Service ${required_service} was killed by the OOM killer. Increase server memory or add swap."
    fi
done

echo "[10/10] Deployment status:"
docker compose ps

echo
echo "Deployment completed and all required health checks passed."
