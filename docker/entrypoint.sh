#!/bin/sh
# Starts a hosted copy of the API: listen on the host's port, prepare Laravel, update the database, run the scheduler.
set -e
cd /var/www/html

PORT="${PORT:-10000}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Folders Laravel writes to.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# A hosted MySQL (Aiven, ...) wants an encrypted connection. Paste its CA certificate, base64-encoded, into DB_SSL_CA_BASE64.
if [ -n "$DB_SSL_CA_BASE64" ]; then
  echo "$DB_SSL_CA_BASE64" | base64 -d > storage/db-ca.pem
  export MYSQL_ATTR_SSL_CA="/var/www/html/storage/db-ca.pem"
fi

php artisan package:discover --ansi
php artisan config:cache

# Build or update the tables, then the first-time jobs (sample data and the first administrator, if asked for).
php artisan migrate --force
php artisan entrypoint:setup

chown -R www-data:www-data storage bootstrap/cache

# The hourly and nightly jobs (fines, due-soon notices, closing old visits, removing old tokens).
su -s /bin/sh www-data -c "php artisan schedule:work" > /dev/null 2>&1 &

exec apache2-foreground
