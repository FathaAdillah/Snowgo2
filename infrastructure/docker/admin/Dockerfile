# syntax=docker/dockerfile:1.7
# Build context: repository root (npm workspaces share one lockfile).

FROM node:24-alpine3.22 AS base
WORKDIR /workspace

FROM base AS dev
# Source is bind-mounted; node_modules live in named volumes populated by `make setup`.
ENV NUXT_HOST=0.0.0.0 NUXT_PORT=3000
CMD ["npm", "run", "dev", "--workspace", "@snowego/admin", "--", "--host", "0.0.0.0", "--port", "3000"]

FROM base AS build
COPY package.json package-lock.json ./
COPY packages/api-types/package.json packages/api-types/
COPY apps/admin/package.json apps/admin/
COPY apps/storefront/package.json apps/storefront/
RUN npm ci --ignore-scripts
COPY packages/api-types packages/api-types
COPY apps/admin apps/admin
RUN npm run build --workspace @snowego/admin

FROM node:24-alpine3.22 AS prod
ENV NODE_ENV=production HOST=0.0.0.0 PORT=3000
WORKDIR /app
COPY --from=build --chown=node:node /workspace/apps/admin/.output ./
USER node
EXPOSE 3000
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD wget -qO- http://127.0.0.1:3000/healthz || exit 1
CMD ["node", "server/index.mjs"]
