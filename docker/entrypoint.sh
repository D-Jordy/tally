#!/bin/sh
set -e

# Fix storage permissions when the directory is volume-mounted from the host.
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || chmod -R 777 storage bootstrap/cache

# On first boot (or after a fresh DB), run migrations automatically.
php artisan migrate --force --no-interaction

# Filament's published JS/CSS/fonts are gitignored and the source bind-mount shadows
# anything baked into the image, so regenerate them into public/ on every boot.
php artisan filament:assets

exec "$@"
