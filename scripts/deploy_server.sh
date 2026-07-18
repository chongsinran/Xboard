#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is not installed." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose is not available." >&2
    exit 1
fi

echo "[1/8] Initializing Git submodules..."
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

# Docker Compose shares Redis with PHP through /data/redis.sock.
sed -i \
    -e 's#^REDIS_HOST=.*#REDIS_HOST=/data/redis.sock#' \
    -e 's#^REDIS_PORT=.*#REDIS_PORT=0#' \
    -e 's#^REDIS_PASSWORD=.*#REDIS_PASSWORD=null#' \
    .env

echo "[2/8] Pulling container images..."
docker compose pull

echo "[3/8] Starting Redis..."
docker compose up -d redis
for attempt in {1..30}; do
    if docker compose exec -T redis redis-cli -s /data/redis.sock ping 2>/dev/null | grep -q PONG; then
        break
    fi
    if [[ "$attempt" == 30 ]]; then
        echo "Redis did not become ready." >&2
        exit 1
    fi
    sleep 1
done

echo "[4/8] Installing locked PHP dependencies..."
docker compose run --rm --entrypoint composer web \
    install --no-dev --prefer-dist --no-interaction --optimize-autoloader

echo "[5/8] Applying the admin bundle patch..."
docker compose run --rm --entrypoint php web scripts/patch_ios_admin_bundle.php

if grep -q '^INSTALLED=true' .env; then
    echo "[6/8] Updating the existing Xboard installation..."
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

    echo "[6/8] Installing a fresh Xboard database..."
    docker compose run --rm \
        -e ENABLE_SQLITE=true \
        -e ENABLE_REDIS=true \
        -e ADMIN_ACCOUNT="$ADMIN_ACCOUNT" \
        web php artisan xboard:install
fi

echo "[7/8] Recreating Xboard services..."
docker compose up -d --force-recreate
docker compose run --rm web php artisan optimize:clear

echo "[8/8] Deployment status:"
docker compose ps

echo
echo "Deployment completed."
