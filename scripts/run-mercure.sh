#!/usr/bin/env bash
#
# Runs the standalone Mercure 1.0 hub (see scripts/install-mercure.sh),
# deriving the Caddyfile.mercure placeholders from this project's own .env
# instead of hardcoding them in composer.json's "devWithOctane" script.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

if [ ! -x ./mercure ]; then
    echo "mercure binary not found — run: composer run mercure:install" >&2
    exit 1
fi

: "${MERCURE_URL:?MERCURE_URL must be set in .env}"
: "${MERCURE_JWT_SECRET:?MERCURE_JWT_SECRET must be set in .env}"

# MERCURE_URL is the full hub endpoint (e.g. http://localhost:8004/.well-known/mercure);
# Caddyfile.mercure's site address is just the scheme+host+port.
export MERCURE_PUBLIC_URL="${MERCURE_URL%/.well-known/mercure}"
export MERCURE_JWT_ISSUER="${APP_URL:-http://localhost}"
export MERCURE_COOKIE_NAME="${MERCURE_COOKIE_NAME:-mercure_access_token}"
export MERCURE_TRANSPORT_PATH="${MERCURE_TRANSPORT_PATH:-storage/app/mercure.db}"

exec ./mercure run --config Caddyfile.mercure
