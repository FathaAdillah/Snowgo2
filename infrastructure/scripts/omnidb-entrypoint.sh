#!/bin/sh
# Dev-only OmniDB at /db: bootstraps the login account and a saved Snowego PostgreSQL connection once.
set -eu

server="python omnidb-server.py"

if ! $server -l -j 2>/dev/null | tail -n 1 | grep -q "\"username\": \"${DBTOOLS_USER}\""; then
    $server -s "$DBTOOLS_USER" "$DBTOOLS_PASSWORD"
    $server -c "$DBTOOLS_USER" postgresql "Snowego (dev)" postgres 5432 \
        "$DB_DATABASE" "$DB_USERNAME" "$DB_PASSWORD"
fi

exec $server -H 0.0.0.0 -p 8000 -P /db
