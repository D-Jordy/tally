#!/bin/sh
set -e

# On first boot (or after a fresh DB), run migrations automatically.
php artisan migrate --force --no-interaction

exec "$@"
