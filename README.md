# Snowego E-Commerce

Rancangan platform untuk siap kirim, preorder, dan request order Snowe GO. Lihat [indeks dokumentasi](docs/README.md) untuk review blueprint, keputusan arsitektur, dan backlog implementasi.

## Struktur repository

| Path | Isi |
|---|---|
| `apps/api` | Laravel 13 modular monolith (PHP 8.4). Modul domain di `app/Modules/<Modul>` memiliki route dan migration sendiri; didaftarkan di `config/modules.php`. |
| `apps/admin` | Nuxt 4 SPA + Nuxt UI untuk staff (`admin.localhost`). |
| `apps/storefront` | Nuxt 4 SSR + Tailwind CSS 4 untuk customer (`localhost`). |
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

### Kontrak API dan CI

Setelah mengubah route, validasi, atau respons API v1, jalankan `make migrate` lalu `make contracts`.
Scramble membaca skema PostgreSQL development saat mengekspor tipe model.
Review dan commit `apps/api/api.json` (hasil Scramble) dan `packages/api-types/src/openapi.d.ts`
(hasil openapi-typescript) bersama kode API. Jangan edit hasil generasi secara manual.
`make contracts-check` memeriksa kedua arah drift tanpa menulis file; `make test-contract`
menguji respons Laravel lewat HTTP terhadap skema yang di-commit.

Admin dan storefront mengimpor `paths`, `components`, dan tipe respons dari `@snowego/api-types`.
Untuk browser gunakan `createApiClient().GET('/health')`; saat SSR gunakan `useFetch` same-origin
dengan tipe respons hasil generasi agar request tetap lewat Nitro. Untuk upload kirim `File`
dengan `bodySerializer: multipartBody`, `Idempotency-Key`, dan `X-XSRF-TOKEN`. Endpoint internal
dan rencana fitur yang belum diimplementasikan tidak masuk kontrak publik.

GitHub Actions memeriksa drift, lint, typecheck, Pest pada PostgreSQL 16, dan kontrak HTTP.
CI juga menjalankan Composer audit, npm audit (HIGH ke atas), serta build dan scan tiga image
produksi (memblokir CRITICAL) tanpa publish atau deploy.

| URL | Service |
|---|---|
| http://localhost | Storefront |
| http://admin.localhost | Admin |
| http://localhost/api/v1/health | API liveness |
| http://admin.localhost/db | OmniDB, dev only. Login superadmin + MFA, lalu login OmniDB (`DBTOOLS_USER` / `DBTOOLS_PASSWORD`). |
| http://admin.localhost/log-viewerx | [Log Viewer](https://github.com/opcodesio/log-viewer) read-only, dev only. Login superadmin + MFA; **Profil → Log Viewer**. |

[Aktivasi superadmin pertama dan login staf (port 8080)](docs/10-DEPLOYMENT.md#login-staf-dan-superadmin-pertama).
| http://127.0.0.1:8025 | Mailpit |
| http://127.0.0.1:9001 | MinIO console |

Readiness internal (DB, Redis, private bucket): `docker compose exec api-nginx wget -qO- http://127.0.0.1:8000/api/v1/internal/readiness`.
