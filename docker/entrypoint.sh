#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

# APP_KEY: iisa dapat sa api at scheduler, galing sa .env sa tabi ng docker-compose.yml
# (ipinapasa ng docker-compose.yml bilang environment variable).
# Kapag naka-set ito, HUWAG gumawa ng bagong key.
if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: Walang APP_KEY. Ilagay sa .env sa tabi ng docker-compose.yml." >&2
    echo "       Gumawa ng key gamit ang: php artisan key:generate --show" >&2
    exit 1
fi

# Ang api lang ang nagma-migrate; ang scheduler ay may RUN_MIGRATIONS=false.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
