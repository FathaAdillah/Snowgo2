# Reference Architecture Analysis — CrowdFunding → Snowego

| | |
|---|---|
| Dokumen | REFERENCE-ARCHITECTURE-ANALYSIS |
| Status | Draft v1.0 — menunggu review |
| Sumber analisis | `C:\laragon\www\CrowdFunding` (branch `master`, commit `3db934d`), dibaca read-only |
| Dokumen turunan | [REQUIREMENT-GAP-ANALYSIS](REQUIREMENT-GAP-ANALYSIS.md), [03-TECH-STACK-ARCHITECTURE](03-TECH-STACK-ARCHITECTURE.md) |

Dokumen ini mencatat pola arsitektur project CrowdFunding (platform crowdfunding + CRM Baitul Maal Arrahmah) yang dipakai sebagai referensi struktur, infrastruktur, dan konvensi development Snowego. Business logic crowdfunding (donasi, donor scoring, qurban, tabungan, opportunity engine) **tidak** dibawa ke Snowego.

Penanda yang dipakai:

- **REUSE** — pola layak dipakai ulang apa adanya.
- **ADAPT** — pola dipakai dengan penyesuaian.
- **FIX** — pola memiliki masalah dan tidak disalin; perbaikannya dicatat.

---

## 1. Struktur Root Project

```text
CrowdFunding/
├── apps/
│   ├── api/        Laravel (composer: laravel/framework ^13.17, PHP ^8.3)
│   ├── admin/      Vue 3 + Vite SPA, Pinia, vue-router, @nuxt/ui v4 (tanpa Nuxt)
│   └── web/        Next.js 15 (App Router, React 19), Tailwind 4
├── packages/
│   ├── openapi/        openapi.yaml (46 baris, hanya /health)
│   └── shared-types/   openapi-typescript → src/schema.d.ts
├── docker/
│   ├── compose.yaml, compose.prod.yaml, .env.example
│   ├── api/ admin/ web/   Dockerfile per app
│   └── nginx/nginx.conf, nginx/vhosts/app.conf, app.prod.conf
├── scripts/yaml-check.php
├── docs/ (BRD, specs/00–02, superpowers/plans + specs bertanggal)
├── .qoder/skills/ (panduan agent per domain & konvensi)
├── Makefile, README.md (rollout status per modul), .env.dokploy
```

| Temuan | Penilaian |
|---|---|
| Monorepo `apps/*` + `packages/*` + folder infrastruktur terpisah | **REUSE** — satu repo memudahkan sinkronisasi kontrak API dan rilis bersama. |
| `Makefile` sebagai entry point (`setup`, `up`, `migrate`, `test`, `lint`, `prod-*`) | **REUSE** |
| README memuat tabel rollout status per fase/modul dengan link plan | **ADAPT** — di Snowego status dipindah ke [11-TASK-PROGRESS-TRACKER](11-TASK-PROGRESS-TRACKER.md) sebagai single source of truth; README hanya menautkan. |
| README menyebut "Laravel 12", composer sudah Laravel 13 | **FIX** — dokumentasi drift. Versi stack hanya ditulis di satu tempat (03-TECH-STACK-ARCHITECTURE) dan dicek saat review. |
| `.qoder/skills` berisi konvensi untuk AI agent | **ADAPT** — Snowego menaruh konvensi di `docs/` (sumber utama) agar agent apa pun dapat membacanya. |

## 2. Struktur Apps / Services

### 2.1 Backend (`apps/api`)

```text
app/
├── Actions/Fortify          aksi bawaan Fortify
├── Console/Commands
├── Enums/                   Permission, Role, CampaignStatus (global)
├── Http/Controllers/        Api/, Admin/, Public/ (di luar modul)
├── Models/                  Campaign, Donor, User, Organization, ... (di luar modul)
├── Modules/<Module>/        17 modul: Enums, Http/Controllers/{Admin,Public,Portal,Webhook},
│                            Jobs, Models, Services, Adapters, Contracts, Exceptions,
│                            Events, Listeners, Console, README.md
├── Providers/
└── Support/RbacCatalog.php
routes/api.php               satu file, ±649 baris, seluruh modul
database/migrations          71 migration terpusat
```

| Temuan | Penilaian |
|---|---|
| Modular monolith di `app/Modules/<Module>` dengan README batas modul | **REUSE** |
| Aturan antar-modul: hanya lewat domain event atau interface publik | **REUSE**, tetapi aturan ini dilanggar di kode (mis. `DonationService` memakai `App\Models\Campaign`; `GatewayWebhookController` memakai model Donation, Qurban, Tabungan). **FIX**: Snowego menegakkan aturan dengan arsitektur test (Pest arch test) yang gagal bila modul mengimpor `Models` modul lain. |
| Sebagian model & controller tetap di `app/Models` / `app/Http/Controllers` | **FIX** — semua kode domain berada di modul; `app/` di luar modul hanya untuk shared kernel. |
| Satu `routes/api.php` besar | **FIX** — tiap modul memiliki file route sendiri (`Routes/public.php`, `customer.php`, `admin.php`, `webhook.php`) yang didaftarkan service provider modul. |
| Migration terpusat | **FIX** — migration disimpan per modul (`Database/Migrations`) dan didaftarkan via `loadMigrationsFrom`. Urutan antar-modul dijaga dengan prefix timestamp. |
| Validasi inline `$request->validate()` di controller | **FIX** — FormRequest per endpoint (validasi + authorization) agar OpenAPI dapat digenerate otomatis. |
| Response `response()->json()` ad-hoc (303 pemanggilan) + `transform()` privat | **FIX** — API Resource (`JsonResource`) per entity dan envelope respons seragam. |
| Query, filter, dan scoping cabang ditulis di controller | **FIX** — controller tipis; query di Query object / repository modul, mutasi di Action. |
| Service class per use case (`DonationService`, `DonationAdjustmentService`) | **ADAPT** — Snowego memakai Action class per use case (`ReserveStockAction`, `VerifyPaymentAction`) dan Service hanya untuk logika domain bersama. |
| Command modul didaftarkan manual di `bootstrap/app.php` | **ADAPT** — didaftarkan oleh service provider modul. |

### 2.2 Frontend

| App | Temuan | Penilaian |
|---|---|---|
| `apps/admin` | Vue 3 + Vite SPA, Pinia store per domain, `lib/api.ts` (fetch wrapper + `ApiRequestError` yang meratakan error validasi Laravel), komponen reusable (`CurrencyInput`, `RowActions`, `TurnstileWidget`), Nuxt UI v4 dipakai **tanpa** Nuxt | **ADAPT** — Nuxt UI tetap dipakai, framework dinaikkan ke Nuxt 4 agar mendapat routing berbasis file, server route (proxy same-origin), dan auto-import. Lihat [ADR-001](adr/ADR-001-frontend-framework.md). |
| `apps/admin` | Token Sanctum disimpan di `localStorage` dan dikirim sebagai Bearer | **FIX** — rentan dicuri lewat XSS. Snowego memakai cookie session Sanctum (HttpOnly) lewat proxy same-origin. |
| `apps/admin` | Build script `vite build && vue-tsc -b`; lint dijalankan `\|\| true` di Makefile | **FIX** — typecheck dan lint wajib lulus di CI. |
| `apps/web` | Next.js App Router, standalone output, dua URL API (`NEXT_PUBLIC_API_URL` untuk browser dan `API_URL_INTERNAL` untuk SSR di jaringan Docker) | **REUSE** konsep dual URL (di Nuxt: `runtimeConfig.public.apiBase` dan `runtimeConfig.apiInternalBase`). Framework diganti Nuxt 4 agar admin dan storefront satu bahasa (Vue). |
| Kedua frontend | Framework berbeda (React dan Vue) | **FIX** — menggandakan komponen, util, dan keahlian tim. |

## 3. Docker

### 3.1 Compose (`docker/compose.yaml`)

Topologi berlapis dengan healthcheck sebagai gerbang:

```text
Layer 1  postgres:16-alpine, redis:7-alpine, minio (+ minio-init)
Layer 2  migrate (one-shot: migrate + db:seed)
Layer 3  api (php-fpm, healthcheck artisan health:check)
Layer 4  worker ×2 (queue:work redis), scheduler (loop schedule:run)
Layer 5  admin (nginx static :8080), web (Next standalone :3000)
Layer 6  nginx :80 (vhost api./app./admin./minio.localhost)
```

| Temuan | Penilaian |
|---|---|
| Urutan start dengan `depends_on.condition: service_healthy / service_completed_successfully` | **REUSE** |
| YAML anchor `x-api-env` untuk env bersama api/worker/scheduler/migrate | **REUSE** |
| Secret lewat `docker/.env` (gitignored) dengan default lokal throwaway | **REUSE** |
| Bind mount `docker/data/*` untuk data lokal | **ADAPT** — Snowego memakai named volume agar tidak bermasalah permission di Windows/WSL. |
| `minio/minio:latest` dan `minio/mc:latest` di dev (prod sudah pin versi) | **FIX** — semua image dipin ke versi spesifik di dev dan prod. |
| `minio-init` memberi bucket `bma-media` policy anonymous download; bukti transfer hanya dilindungi `deny` path di nginx | **FIX** — Snowego memakai **dua bucket**: `snowego-public` (gambar katalog) dan `snowego-private` (bukti transfer, evidence, invoice PDF) tanpa policy publik, dengan akses lewat signed URL berumur pendek. |
| `migrate` menunggu dengan `sleep 10` lalu menjalankan `db:seed` setiap boot | **FIX** — tidak perlu sleep karena sudah ada healthcheck; seeder produksi hanya untuk data referensi yang idempotent (permission, role, wilayah), dipisah dari seeder demo. |
| Image api dibangun 4× (migrate/api/worker/scheduler) tanpa tag bersama | **FIX** — satu image `snowego/api:<tag>` dipakai bersama oleh service-service tersebut. |
| Worker `queue:work redis --sleep=3 --tries=3` tanpa `--queue`, padahal kode men-dispatch job ke queue `high` (`ProcessPaidDonation`) | **FIX** — dengan konfigurasi ini job di queue `high` tidak dikonsumsi worker compose (inferensi dari konfigurasi; belum diuji di runtime). Snowego memakai **Laravel Horizon** dengan supervisor per queue (`critical`, `default`, `notifications`, `low`) yang dideklarasikan eksplisit. |
| Scheduler berupa loop shell `while true; schedule:run; sleep 60` | **FIX** — gunakan `php artisan schedule:work` (bawaan Laravel) dan `withoutOverlapping()` + `onOneServer()` pada job. |
| Tidak ada Mailpit; `MAIL_MAILER` default `log` | **FIX** — Mailpit menjadi service dev standar. |

### 3.2 Dockerfile

| File | Temuan | Penilaian |
|---|---|---|
| `docker/api/Dockerfile` | `php:8.3.x-fpm-alpine`, ekstensi pdo_pgsql, intl, zip, opcache, gd (dengan catatan GD+DomPDF), redis via pecl; composer install dengan retry & BuildKit cache; menghapus `.env` dari image | **REUSE** dengan multi-stage (stage composer terpisah) dan base PHP 8.4. |
| `docker/admin/Dockerfile` | Build Vite lalu disajikan nginx static | **ADAPT** — Nuxt admin memakai Nitro server (`node .output/server/index.mjs`) karena butuh server route proxy. |
| `docker/web/Dockerfile` | Multi-stage deps → build → run standalone | **REUSE** pola multi-stage untuk storefront Nuxt. |
| `.dockerignore` | Mengecualikan `.env`, vendor, node_modules, build artefak, tests, docs | **REUSE** |

## 4. Nginx / Reverse Proxy

| Temuan | Penilaian |
|---|---|
| Security header di level `http` (nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy) dengan catatan bahwa `add_header` di child block menimpa parent | **REUSE** |
| `limit_req_zone` untuk endpoint publik/webhook | **REUSE** |
| `client_max_body_size 20m` | **ADAPT** — batas upload ditentukan per jenis file di aplikasi; proxy 25 MiB sebagai batas transport, dengan batas MIME dan ukuran aplikasi per koleksi. |
| CORS hanya di Laravel (`HandleCors`), tidak di nginx | **REUSE** |
| Log-viewer diproxy lewat host admin | **FIX** — tidak dipakai; log JSON ke stdout + Horizon dashboard dengan gate permission. |
| HSTS sengaja tidak diset karena TLS di Cloudflare edge | **ADAPT** — tergantung topologi produksi (lihat [10-DEPLOYMENT](10-DEPLOYMENT.md)). |

## 5. Laravel API Structure, Response & Exception

| Aspek | Temuan | Penilaian |
|---|---|---|
| Prefix route | `/api/...` tanpa versi | **FIX** — `/api/v1`. |
| Grup route | Public, auth, portal (donatur), admin, webhook | **REUSE** — Snowego: Public, Customer, Admin, Webhook, Internal/System. |
| Bentuk respons sukses | `{ data }`, `{ message, data }`, `{ token, user }` | **FIX** — envelope tunggal `{ success, message, data, meta }`. |
| Bentuk respons error | `{ message }`, `{ message, errors }` (validasi Laravel), `{ error: { code, message } }` (webhook) | **FIX** — `{ success: false, message, code, errors?, meta.request_id }` untuk semua error. |
| Exception handling | `shouldRenderJsonWhen(api/*)`, `report()` mencatat method/url/file | **ADAPT** — tambah mapping exception domain → kode error (mis. `InvalidStateTransition` → 409 `INVALID_STATE_TRANSITION`) dan request ID. |
| Health | `HealthController` cek database + cache, 503 bila gagal | **ADAPT** — tambah MinIO, queue (Horizon status), mail (dev). |
| OpenAPI | File manual 46 baris, hanya `/health` → drift total | **FIX** — OpenAPI digenerate dari kode (FormRequest + Resource) dan diverifikasi di CI. |

## 6. Environment Files

| Temuan | Penilaian |
|---|---|
| `docker/.env.example` untuk interpolasi compose; `apps/api/.env` tidak pernah masuk image | **REUSE** |
| `.env.dokploy` di root berisi daftar variabel produksi (nama variabel terlihat; nilai tidak dibaca saat analisis) | **ADAPT** — Snowego menyimpan `.env.example` per environment tanpa nilai rahasia; nilai produksi hanya di secret manager platform. |
| Domain per environment via variabel (`WEB_DOMAIN`, `ADMIN_DOMAIN`, `API_DOMAIN`, `MINIO_DOMAIN`) | **REUSE** |
| `MIGRATE_MODE` configurable di produksi | **REUSE** |

## 7. PostgreSQL, Redis, MinIO, Mailpit, Queue, Scheduler

| Komponen | Temuan | Penilaian |
|---|---|---|
| PostgreSQL 16 | Satu database transaksional; healthcheck `pg_isready` | **REUSE** |
| Uang | Mayoritas `unsignedBigInteger` (rupiah utuh), satu kolom `decimal(15,2)` | **ADAPT** — satu konvensi: `bigint` rupiah utuh + `currency`, lihat [04-DATABASE-SCHEMA §2](04-DATABASE-SCHEMA.md). |
| JSON | `json` di `audit_log` | **FIX** — `jsonb`. |
| Locking | `lockForUpdate()` di dalam `DB::transaction` (29 pemakaian) | **REUSE**, ditambah conditional update atomik untuk stok dan CHECK constraint. |
| Redis | Cache, session, queue | **ADAPT** — session Snowego di PostgreSQL (agar daftar device/session dapat dilihat dan dicabut); Redis untuk cache, queue, rate limiter, lock. |
| Queue | Tiga queue (`high`, `default`, `low`) didokumentasikan; `after_commit=false` | **FIX** — `after_commit=true` agar job tidak berjalan sebelum transaksi commit; konsumsi queue eksplisit di Horizon. |
| Scheduler | Didefinisikan di `bootstrap/app.php` (RFM 01:00, opportunity 02:00, SLA hourly, `sanctum:prune-expired`) | **ADAPT** — tiap modul mendaftarkan jadwalnya; job kritikal memakai `withoutOverlapping` + `onOneServer`. |
| MinIO | Disk `minio` (driver s3, path-style, `throw=true`) | **REUSE** driver S3; **FIX** bucket publik (lihat §3.1). |
| Mailpit | Tidak ada | **FIX** — ditambahkan. |

## 8. Authentication & Authorization

| Temuan | Penilaian |
|---|---|
| Sanctum personal access token 30 hari; login admin menghapus semua token lama (satu sesi) | **FIX** — Snowego memakai Sanctum SPA cookie session; daftar sesi per device dan revoke per sesi. |
| Fortify untuk MFA TOTP staff | **REUSE** (wajib untuk akun staff). |
| Cloudflare Turnstile di login/register | **ADAPT** — opsional, diaktifkan lewat setting; rate limit tetap menjadi pertahanan utama. |
| `RateLimiter::for('login')`, `throttle:N,1` di route publik | **REUSE** |
| Spatie Permission; string permission `<module>.<resource>.<action>` di `App\Enums\Permission`; role hanya dibuat oleh seeder | **REUSE** |
| `RbacCatalog` mengelompokkan permission untuk UI role editor + test bahwa katalog = semua permission | **REUSE** |
| Super admin = flag `users.is_admin` yang mem-bypass lewat `Gate::before` | **FIX TASK-007** — bukan flag user: hanya role sistem `web:super_admin` mendapat seluruh permission terdaftar setelah login + MFA; policy resource dan step-up tidak dilewati. |
| Scoping data per cabang (organization) di controller | Tidak relevan untuk Snowego (satu toko). Scoping per gudang dipertimbangkan sebagai Future. |

## 9. Logging, Audit, Domain Pattern

| Temuan | Penilaian |
|---|---|
| `audit_log` immutable (actor, action, entity, old/new, IP, UA, created_at) untuk aksi finansial | **REUSE** (nama `audit_logs`, `jsonb`, + `request_id`). |
| State machine di enum (`allowedTransitions()`, `canTransitionTo()`) + `Model::transitionTo()` yang melempar exception | **REUSE** — diperluas dengan pencatatan otomatis ke `status_histories`. |
| Webhook: verifikasi signature → normalisasi payload → idempotency `Cache::add` 30 detik → dispatch → respons 200 < 3 detik | **ADAPT** — idempotency permanen di tabel `webhook_events` (unique per event) karena lock Redis 30 detik tidak melindungi retry yang datang lebih lambat. |
| Payment gateway: `PaymentGatewayInterface` + adapter (Xendit, DOKU, BRI) + `PaymentGatewayManager` | **REUSE** pola provider abstraction untuk payment dan shipping. |
| Pemanggilan API gateway eksternal sebelum transaksi DB | **ADAPT** — pola diterima untuk create invoice gateway, tetapi Snowego mendokumentasikan kompensasi (invoice yatim kedaluwarsa sendiri). |
| Log `stack` → `single` file | **FIX** — log JSON ke stdout dengan `request_id`, `user_id`, `module`. |
| DomPDF untuk kwitansi | **REUSE** untuk invoice PDF. |

## 10. Testing, CI/CD, Dokumentasi

| Temuan | Penilaian |
|---|---|
| 70 file test (mayoritas Feature) per modul, fixture via trait `Concerns` | **REUSE** struktur. |
| Test memakai SQLite `:memory:` dan `QUEUE_CONNECTION=sync`; komentar kode mengakui `lockForUpdate` no-op di SQLite | **FIX** — Snowego menjalankan test terhadap PostgreSQL 16 sungguhan (service container CI) sehingga locking, partial index, CHECK, dan `jsonb` ikut teruji. |
| PHPUnit; tidak ada PHPStan/Larastan | **FIX** — Pest + Larastan level 6 (naik bertahap) + Pint. |
| Tidak ada `.github/workflows`; `make test` hanya artisan test + build | **FIX** — GitHub Actions: lint, static analysis, test, build image, OpenAPI drift check. |
| Dokumentasi: BRD + spec arsitektur + plan bertanggal per fitur + skill agent; README rollout status | **ADAPT** — struktur dokumen bernomor 01–11 + ADR + diagram Mermaid sesuai master prompt. README CrowdFunding menandai spec teknis 01–09 sebagai Pending (baru 01 dan 02 yang ditulis), sehingga Snowego menulis seluruh dokumen inti sebelum development. |
| Deployment Dokploy + Cloudflare Tunnel, `COMPOSE_PARALLEL_LIMIT`, prefetch base image | **ADAPT** — lihat [10-DEPLOYMENT](10-DEPLOYMENT.md). |

---

## 11. Ringkasan

### 11.1 Pola yang dipakai ulang

1. Monorepo `apps/api`, `apps/admin`, `apps/<public>`, `packages/*`, infrastruktur terpisah, Makefile.
2. Laravel modular monolith di `app/Modules` dengan README batas modul.
3. Docker Compose berlapis dengan healthcheck, env anchor, migrate one-shot, worker & scheduler dari image yang sama.
4. Nginx security header di level `http`, rate limit zone, CORS hanya di Laravel.
5. Enum state machine + `transitionTo()`.
6. Permission string `<module>.<resource>.<action>`, role dari seeder, katalog permission untuk UI.
7. Provider abstraction (interface + adapter + manager) untuk integrasi eksternal.
8. Audit log immutable untuk aksi kritikal.
9. Dual API base URL (browser vs SSR internal).
10. DomPDF, MinIO via driver S3, Fortify TOTP untuk staff.

### 11.2 Pola yang diperbaiki

| # | Masalah di CrowdFunding | Perbaikan di Snowego |
|---|---|---|
| 1 | Bucket publik + deny path untuk bukti transfer | Bucket private terpisah + signed URL |
| 2 | Token admin di `localStorage` | Cookie session HttpOnly via proxy same-origin |
| 3 | Worker tidak mengonsumsi queue `high` | Horizon dengan supervisor per queue eksplisit |
| 4 | Test di SQLite | Test di PostgreSQL 16 |
| 5 | Tanpa versi API, respons tidak seragam | `/api/v1` + envelope tunggal + kode error |
| 6 | OpenAPI manual yang drift | OpenAPI digenerate + CI drift check |
| 7 | Route & migration terpusat, model di luar modul | Route, migration, model per modul + arch test |
| 8 | Validasi inline, tanpa API Resource | FormRequest + JsonResource |
| 9 | `after_commit=false` | `after_commit=true` |
| 10 | Image `latest` di dev, sleep di migrate | Image dipin, healthcheck saja |
| 11 | Scheduler loop shell | `schedule:work` + `onOneServer` |
| 12 | Super admin bypass `Gate::before` | Role `super_admin` berbasis permission |
| 13 | Tidak ada CI, static analysis, Mailpit | GitHub Actions, Larastan, Mailpit |
| 14 | Idempotency webhook via lock Redis 30 detik | Tabel `webhook_events` unique per event |
| 15 | Aturan antar-modul tidak ditegakkan | Arch test Pest |

### 11.3 Infrastruktur, struktur, dan pola coding yang diturunkan

Hasil adaptasi dirinci di [03-TECH-STACK-ARCHITECTURE](03-TECH-STACK-ARCHITECTURE.md) (repository structure, Docker, network), [ADR-002](adr/ADR-002-backend-architecture.md) (struktur modul), dan [10-DEPLOYMENT](10-DEPLOYMENT.md).
