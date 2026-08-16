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

# The image already ships a seeded database, the storage symlink, and the route
# and event caches. Booting is meant to be "start the server" and nothing else.
# This only fires when there is genuinely nothing to serve, e.g. DB_DATABASE
# pointed somewhere the image never seeded.
if [ ! -s "${DB_DATABASE}" ]; then
    echo "entrypoint: no seeded database at ${DB_DATABASE}; seeding now." >&2
    php artisan storage:link --force
    php artisan migrate:fresh --seed --force
fi

# The one cache that cannot be baked: config:cache freezes the environment into
# the cache file, and the environment only exists here -- APP_KEY from secrets,
# APP_ENV from the platform config. Roughly a second, and it is the only thing
# standing between a cold Machine and a bound port.
php artisan config:cache

# schedule:work drives the 15-minute demo:reset (routes/console.php). queue:work
# drains the notification and mail jobs the workflows dispatch.
#
# ⚠️ Delayed on purpose. Both boot the whole framework, and on a 2-thread shared
# CPU they were competing with the web server for the cores it needed to bind
# :80 -- six seconds of a cold start went here, while the proxy retried and
# backed off in front of a visitor. Neither has anything to do for a minute
# anyway: the scheduler polls on the minute and the queue is empty until someone
# is already using the app.
(
    sleep 20
    php artisan schedule:work &
    php artisan queue:work --tries=1 --sleep=3 &
) &

exec "$@"
