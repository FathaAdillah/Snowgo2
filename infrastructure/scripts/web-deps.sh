#!/bin/sh
set -eu
# One writer for all four workspace volumes, before either Vite server starts.
stamp=node_modules/.snowego-workspace
key=$({ node -p '`${process.version}:${process.arch}`'; sha256sum package-lock.json package.json apps/admin/package.json apps/storefront/package.json packages/api-types/package.json; } | sha256sum)
if [ "$(cat "$stamp" 2>/dev/null || :)" != "$key" ]; then
    npm ci --no-fund --no-audit
    printf '%s\n' "$key" > "$stamp"
fi
