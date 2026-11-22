# Tech stack dan arsitektur

## Batas sistem

Laravel modular monolith menjadi pemilik transaksi dan REST API v1. Storefront dan admin adalah aplikasi Nuxt yang dirilis terpisah. PostgreSQL menjadi sumber kebenaran, Redis untuk cache, rate limit, koordinasi scheduler dan antrean. Compose cukup untuk fase awal. Batas monolith dipertahankan lewat kontrak publik dan kepemilikan data per modul. [System context](diagrams/system-context.md) · [Container](diagrams/container-diagram.md) · [Aliran teknis](diagrams/technical-flows.md)

| Komponen | Pilihan |
|---|---|
| Runtime | Laravel 13, PHP 8.4 (minimum framework 8.3), PHP-FPM, Nginx |
| Data dan async | PostgreSQL 16, Redis 7, Horizon, scheduler Laravel, transactional outbox |
| Storage dan email | MinIO dengan API S3; Mailpit untuk dev/test, SMTP terautentikasi produksi |
| Admin | Nuxt 4, Vue 3, Nuxt UI 4, TypeScript, SPA dengan Nitro untuk proxy |
| Storefront | Nuxt 4, Vue 3, TypeScript, Tailwind 4 dan UI custom; SSR halaman publik |
| Build dan penyajian | Node 24 LTS dengan lockfile, Compose dev/prod, GitHub Actions, image immutable |

## Paket dan dasar pemilihan

| Paket | Kebutuhan | Tujuan dan alasan |
|---|---|---|
| laravel/sanctum | Wajib | Sesi browser dan middleware stateful dengan CSRF |
| laravel/fortify | Wajib | Primitif reset, verifikasi dan TOTP staff yang teruji |
| laravel/socialite | Wajib | OAuth Google melalui driver standar |
| spatie/laravel-permission | Wajib | Katalog permission, role dan cache dalam satu guard |
| laravel/horizon | Wajib | Worker Redis, metrik dan antrean gagal |
| league/flysystem-aws-s3-v3 | Wajib | Abstraksi object storage lintas provider berbasis S3 |
| barryvdh/laravel-dompdf | Wajib | Render invoice dan snapshot HTML yang ditentukan aplikasi |
| brick/money | Wajib | Aritmetika whole-IDR dan rounding eksplisit |
| dedoc/scramble | Wajib setelah spike | OpenAPI dari tipe, request dan response melalui extension untuk envelope; Scribe alternatif bila Laravel 13 atau fidelity bermasalah |
| openapi-typescript | Wajib | Tipe klien kedua frontend dari kontrak yang sama |
| pestphp/pest | Wajib (dev) | Test feature, unit, architecture |
| laravel/pint | Wajib (dev) | Standar formatting Laravel |
| larastan/larastan | Wajib (dev) | Analisis statis Laravel; level awal 6 |
| intervention/image | Opsional | Re-encode dan resize apabila fasilitas framework belum cukup |
| spatie/laravel-medialibrary | Opsional, dilewati MVP | Metadata privasi dan akses sudah diakomodasi stored_files dan policy |

Spike foundation memeriksa constraint paket pada Laravel 13/PHP 8.4, export OpenAPI, dan builds kedua frontend. Referensi versi [Laravel Releases](https://laravel.com/docs/13.x/releases) dan [Nuxt Installation](https://nuxt.com/docs/4.x/getting-started/installation).

## Struktur repository dan runtime

    apps/api
    apps/admin
    apps/storefront
    packages/api-types
    infrastructure/docker/{api,admin,storefront}
    infrastructure/nginx
    infrastructure/scripts
    docs
    docker-compose.yml
    docker-compose.prod.yml
    .env.example
    Makefile

Struktur di atas adalah target development. API, migrate, Horizon, dan scheduler memakai digest image API yang sama. Nginx edge menuju Nitro admin atau storefront. Proxy same-origin untuk /api dan /sanctum meneruskan Host/Origin yang diizinkan, Set-Cookie dan request ID ke Nginx internal/PHP-FPM. OAuth callback storefront melalui proxy yang sama. Sesi host-only dengan CSRF. SSR meneruskan cookie hanya ke upstream tepercaya. Respons pribadi, callback dan file tanpa cache publik. Redis prefix, bucket, mail dan database terpisah per environment.

## Batas modul dan kepemilikan

| Modul | Tanggung jawab atau kontrak publik |
|---|---|
| Foundation | Clock, Money, Idempotency, StoredFiles, Outbox, status history |
| Identity | Identitas pembeli/staff, sesi, MFA, OAuth |
| Customers | Profil, alamat dan wishlist |
| Geography | Hierarki wilayah, referensi unit dan alasan |
| Catalog | Produk, penawaran RTS, varian, harga dan media |
| Inventory | Receipt, adjustment, Reserve, Commit, Release, Ship |
| Preorders | Kampanye, harga per varian, ReserveQuota dan ConfirmQuota |
| Procurement | Pengadaan PO/request, receipt dan shortage per item |
| RequestOrders | Draft penawaran, diskusi dan AcceptQuotation |
| Cart | Cart pembeli berisi penawaran dan pilihan termin |
| Checkout | Preview dan CreateCheckout, orkestrasi reservasi dan pembuatan order |
| Orders | Order, snapshots, fulfillment agregat, pembatalan dan completion |
| Invoicing | Issue, ApplyPayment, Void, Render dan source reference |
| Payments | Intent, proof, settlement, allocations dan port provider |
| Promotions | QuoteDiscount, hold dan finalize kuota Phase 2 |
| Shipping | Quote, paket, dispatch, tracking dan adapter |
| Refunds | Review, cap, transfer dan settlement refund |
| Returns | Review, instruksi, inspeksi dan resolusi |
| Content | Landing page, banner, FAQ dan kebijakan |
| Notifications | Email, inbox, delivery dan template event |
| Search | PostgreSQL search/filter dan port index provider |
| Reporting | Query dashboard dan agregasi baca |
| Administration | Staff/RBAC, konfigurasi, dan audit melalui owner modul |

Satu service provider per modul mendaftarkan route group dan migration. Struktur lazim selevel Modules/Domain adalah Actions, Contracts, DTOs, Enums, Events, Models, Policies, Queries, Routes, Http/{Controllers,Requests,Resources}, Jobs, Database/Migrations. Controller mengikat input dan memanggil Action atau query. Mutasi lintas modul memakai DTO/port pada satu transaksi DB yang dibuka orchestrator. Pemilik data menjalankan lock dan perubahan. Query dashboard mengonsumsi read model/proyeksi atau kontrak read yang ditinjau. ORM model modul lain dilarang oleh arch test.

Tuliskan audit/status dan outbox di transaksi yang sama dengan state. Scheduler mengklaim event PostgreSQL dengan SKIP LOCKED dan lease, publish ke Redis setelah claim commit lalu tandai terbit bila enqueue berhasil; lease kedaluwarsa dan republish bisa menghasilkan duplikat. Consumer memakai kunci unik event + handler. Reconciler memeriksa handler penting yang belum selesai dan re-enqueue secara aman. Side effect HTTP/SMTP/PDF di job setelah commit dengan timeout, retry/backoff dan jalur rekonsiliasi. Redis lock hanya optimasi, integritas ada di constraint/lock PostgreSQL.

| Event | Producer | Consumer contoh |
|---|---|---|
| StockReserved, StockReleased | Inventory | Audit/operasi |
| OrderCreated, OrderPaid, OrderDelivered, OrderCompleted | Orders | Email dan inbox |
| PaymentSubmitted, PaymentApproved, PaymentRejected | Payments | Finance queue, email |
| PreorderConfirmed, PreorderEtaUpdated, ProcurementStageChanged | Preorders/Procurement | Pembaruan pelanggan dan dashboard |
| RequestOrderSubmitted, QuotationSent, QuotationAccepted | RequestOrders | Antrean staff dan notifikasi |
| InvoiceIssued | Invoicing | Render PDF dan email |
| ShipmentCreated, ShipmentStatusUpdated | Shipping | Update order dan notifikasi |
| RefundRequested, RefundApproved, RefundCompleted, ReturnRequested, ReturnResolved | Refunds/Returns | Staff dan pelanggan |

## Storage, integrasi dan release

Public catalog bucket terpisah dari bucket private documents. Upload masuk area quarantine private, lulus validasi dan scan baru dipromosikan. Dokumen private diakses dengan policy dan URL sementara. Payment provider pertama adalah manual transfer. Shipping MVP memilih kurir/service dari katalog dan tarif lokal, mencatat resi dan tracking manual; provider tracking opsional mengikuti ADR. Alamat eksternal dan nominal hanya dikirim jika perlu dan telah diotorisasi. Search memakai PostgreSQL tsvector dengan GIN; indeks hanya memuat katalog terbit.

Dev memakai Compose, PostgreSQL dan Mailpit. Staging meniru jaringan produksi dengan data sintetis dan sandbox. Produksi ber-HTTPS dan memisahkan ingress, service dan data interfaces. Scale via lebih banyak replica API/Nitro dan worker berdasarkan antrean; use case tetap satu transaksi PostgreSQL. Mulai dari backup off-host dan uji restore menurut [Deployment](10-DEPLOYMENT.md). Log JSON, request ID, horizon lag, outbox delay, error provider, dan alert rekonsiliasi didetailkan di deployment.
