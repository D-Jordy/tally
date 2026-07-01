#!/usr/bin/env bash
# Production deploy step, run on the server from /srv/tally after the tag is
# checked out. Builds and (re)starts the stack, then migrates and caches config.
set -euo pipefail

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

$COMPOSE up -d --build

# The app container may still be running its boot entrypoint; retry until the
# migrate command lands, then warm the framework caches for production.
for _ in $(seq 1 15); do
    $COMPOSE exec -T app php artisan migrate --force && break || sleep 4
done

$COMPOSE exec -T app php artisan optimize

docker image prune -f
