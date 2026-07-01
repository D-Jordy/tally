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

# In production the nginx container serves static files from a shared volume
# (mounted at /srv/public here). Publish a fresh copy of public/ into it on every
# boot so redeploys pick up new assets. Absent in dev, where source is bind-mounted.
if [ -d /srv/public ]; then
    rm -rf /srv/public/* 2>/dev/null || true
    cp -a public/. /srv/public/
fi

exec "$@"
