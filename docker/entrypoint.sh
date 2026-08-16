#!/bin/sh
set -e

# APP_KEY should be injected as a secret. If it is not, mint an ephemeral one so
# the container still boots — sessions then die on every restart, which for a
# demo that wipes itself every 15 minutes is not a loss worth solving.
if [ -z "${APP_KEY}" ]; then
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    export APP_KEY
    echo "entrypoint: APP_KEY was unset; generated an ephemeral one." >&2
fi

touch "${DB_DATABASE}"
chown www-data:www-data "${DB_DATABASE}"

php artisan storage:link --force
php artisan migrate:fresh --seed --force

php artisan config:cache
php artisan route:cache
php artisan event:cache

# schedule:work drives the 15-minute reset (routes/console.php). queue:work
# drains the notification and mail jobs the workflows dispatch.
php artisan schedule:work &
php artisan queue:work --tries=1 --sleep=3 &

exec "$@"
