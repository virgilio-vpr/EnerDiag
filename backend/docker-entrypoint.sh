#!/bin/sh
set -eu
umask 077
mkdir -p storage/app/private/invoice-temp storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chmod 700 storage/app/private/invoice-temp
php artisan config:cache --no-interaction
exec "$@"
