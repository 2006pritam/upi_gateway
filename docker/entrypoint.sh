#!/bin/sh
set -e
cd /var/www/html

# The web installer writes .env and storage/installed, so the app root must be
# writable by the web user. Seed .env with the APP_KEY from the environment so
# the wizard keeps that key instead of generating a different one mid-session.
# (Render's filesystem is ephemeral; real configuration lives in env vars.)
if [ -z "${APP_KEY:-}" ]; then
    echo "WARNING: APP_KEY is not set. Sessions will break on every restart." >&2
fi
if [ ! -f .env ]; then
    ( umask 077; printf 'APP_KEY=%s\n' "${APP_KEY:-}" > .env )
fi
chown www-data:www-data . .env
chmod 600 .env

# Opt-in: apply pending migrations on boot (after a database backup!).
# The first install is done through the web wizard instead.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    runuser -u www-data -- php artisan migrate --force \
        || echo "WARNING: migrations failed; continuing to start." >&2
fi

# Queue worker and scheduler (orders:check-pending runs every minute).
# They retry quietly until the database exists, e.g. before the wizard has run.
run_forever() {
    while true; do
        runuser -u www-data -- "$@" >/dev/null 2>&1 || true
        sleep 5
    done
}
run_forever php artisan queue:work --sleep=3 --tries=3 --max-time=3600 &
run_forever php artisan schedule:work &

exec apache2-foreground
