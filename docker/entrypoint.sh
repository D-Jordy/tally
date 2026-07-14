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

# public/build is a named volume, and Docker only seeds a named volume from the image
# the first time it is created. Without this, a redeployed image keeps serving the Vite
# bundle from the very first build. Refresh it from the copy stashed in the image.
if [ -d /opt/vite-build ]; then
    mkdir -p public/build
    rm -rf public/build/*
    cp -a /opt/vite-build/. public/build/
fi

exec "$@"
