# Snowego E-Commerce

Rancangan platform untuk siap kirim, preorder, dan request order Snowe GO. Lihat [indeks dokumentasi](docs/README.md) untuk review blueprint, keputusan arsitektur, dan backlog implementasi.

## Struktur repository

| Path | Isi |
|---|---|
| `apps/api` | Laravel 13 modular monolith (PHP 8.4). Modul domain di `app/Modules/<Modul>` memiliki route dan migration sendiri; didaftarkan di `config/modules.php`. |
| `apps/admin` | Nuxt 4 SPA + Nuxt UI untuk staff (`admin.snowego.localhost`). |
| `apps/storefront` | Nuxt 4 SSR + Tailwind CSS 4 untuk customer (`snowego.localhost`). |
| `packages/api-types` | Tipe TypeScript envelope/kontrak API v1 yang dipakai kedua frontend. |
| `infrastructure/docker` | Dockerfile API (php-fpm, Horizon, scheduler, migrate) dan kedua Nitro app. |
| `infrastructure/nginx` | Edge ingress (routing hostname) dan upstream privat Nginx → PHP-FPM. |
| `infrastructure/scripts` | Init bucket MinIO dan health check container. |

Browser hanya berbicara dengan Nitro; `/api/*` dan `/sanctum/*` di-proxy same-origin ke Laravel. `/api/v1/internal/*` diblokir di edge.

## Development

Prasyarat: Docker Compose v2.24+, Make, OpenSSL. Node 24 dan PHP 8.4 lokal opsional (semua perintah berjalan di container).

```sh
make setup   # .env, APP_KEY, build image, composer/npm install, migrate
make up      # jalankan stack dan tunggu sampai sehat
make test    # Pest (PostgreSQL/Redis/MinIO) + typecheck frontend
make lint    # Pint, Larastan, ESLint
make down    # hentikan stack, volume tetap
```

| URL | Service |
|---|---|
| http://snowego.localhost | Storefront |
| http://admin.snowego.localhost | Admin |
| http://snowego.localhost/api/v1/health | API liveness |
| http://127.0.0.1:8025 | Mailpit |
| http://127.0.0.1:9001 | MinIO console |

Readiness internal (DB, Redis, private bucket): `docker compose exec api-nginx wget -qO- http://127.0.0.1:8000/api/v1/internal/readiness`.
