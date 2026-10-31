#!/usr/bin/env bash
set -e

cd /var/www/html

# Listen on the port Render gives us.
PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

if [ -z "${APP_KEY}" ]; then
    echo "WARNING: APP_KEY is not set. Generating a temporary one — set APP_KEY in Render to keep it stable."
    export APP_KEY="$(php artisan key:generate --show --no-ansi)"
fi

php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache || echo "Route cache skipped."

# Create / update the database tables.
php artisan migrate --force

# Load the sample data the first time (set SEED_DEMO_DATA=false to skip).
if [ "${SEED_DEMO_DATA:-true}" = "true" ]; then
    php artisan campus:seed-if-empty
fi

chown -R www-data:www-data storage bootstrap/cache

exec apache2-foreground
