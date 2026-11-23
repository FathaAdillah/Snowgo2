# Deployment dan operasi

## Environment dan network

| Environment | Ingress dan data | Mail, provider dan credential |
|---|---|---|
| Development | Compose di workstation, localhost dan admin.localhost. OmniDB dan Log Viewer hanya pada host admin dengan sesi MFA superadmin. PostgreSQL/Redis internal; MinIO API/console dan Mailpit UI lewat loopback. | Mailpit SMTP 1025, UI 8025, local-only; Google sandbox, fake shipping/payment provider; dummy secrets hanya di env lokal |
| Staging | Hostname terpisah, HTTPS, arsitektur setara produksi, DB/bucket/Redis terpisah. Data sintetis. | Mailpit atau SMTP sandbox allowlist; credential sandbox, jobs aktif |
| Production | snowego.shop dan admin.snowego.shop di edge TLS. DNS/ingress ke Nginx, Nitro → private API upstream. Private network untuk DB, Redis, queue, scheduler, MinIO console dan metrics. | SMTP produksi dengan SPF/DKIM/DMARC; secret references dan IAM terpisah. URL private storage presigned lewat object endpoint HTTPS yang kompatibel dengan Host yang ditandatangani. |

API publik terjangkau same-origin melalui storefront/admin menurut route surface. Probe internal dan console diblokir di edge. TLS edge → upstream harus sesuai trust boundary, HSTS setelah domain siap, Nginx request ID/limits dan forwarded header allowlist. Named volumes lokal, volume data produksi dengan backup off-host. Dokploy/Cloudflare Tunnel merupakan opsi, sesuai Q-08.

## Compose dan environment contract

1. Ingress, api-nginx/PHP-FPM, Nuxt admin, Nuxt storefront, redis dan postgres adalah service terpisah. MinIO menyimpan media dan dokumen, scan service memeriksa quarantine; Mailpit hanya dev/staging sandbox.
2. Init bucket/idempotent policy setelah object storage sehat. Migrate one-shot bergantung DB readiness. API memakai liveness dan readiness berbeda. Worker Horizon dan scheduler memakai digest image sama dengan API. Demo seed hanya untuk dev/staging sintetis.
3. Set APP_KEY, APP_ENV, APP_URL, SESSION_*, SANCTUM_STATEFUL_DOMAINS, TRUSTED_PROXIES, DB_*, REDIS_*, QUEUE_CONNECTION, MAIL_*, S3_*, GOOGLE_*, dan provider references melalui konfigurasi lingkungan. Nitro menggunakan API internal origin dan config publik tanpa secret. Set dev cookie behavior eksplisit, Secure wajib staging/prod.
4. Target perintah development: make setup, make up, make migrate, make test, make lint; make down menjaga volume. Service lokal health checks dan Mailpit UI masuk smoke. Service tidak memakai tag latest, build dan lockfile dikunci.

| Probe | Sinyal dan tindak lanjut |
|---|---|
| API liveness | Process/route hidup; 200 tubuh minimal, restart jika gagal persisten |
| API readiness internal | DB login/migration compatible, Redis ping, private bucket probe; 503 jika dependensi wajib gagal |
| Queue | Horizon supervisor heartbeat, lag/dead letter; alert bila critical lag > 60 detik selama 5 menit (ASSUMPTION) |
| Scheduler / outbox | Heartbeat tiap menit dan oldest unpublished; alert > 3 menit, check lease, queue dan DB |
| SMTP/Mailpit | Dev SMTP/HTTP health dan test email; prod delivery failure / bounce di dashboard |
| Provider dan storage | Latency/error/429, signed object smoke, scanner backlog; fallback manual untuk shipping |
| Bisnis | Payment unreviewed lewat SLA, reservation expired masih aktif, capture/refund mismatch; finance dan operator menerima antrean |

Runbook initial: filter logs berdasarkan request ID dan job ID. Cek DB/Redis/storage probes, Horizon failed jobs dan outbox backlog. Untuk retry, cocokkan event key, DB state dan apakah side effect eksternal sudah terjadi. Replay job only dari objek sumber dengan audit/lease; untuk transfer ambigu cek mutasi sebelum retry. Jadwal default, expiry tiap menit, quote/charge expiry tiap menit, completion tiap jam, rekonsiliasi dan backup harian, polling provider Phase 2 sesuai policy. Horizon queue critical, default, notifications, low memiliki supervisor eksplisit, retry backoff/jitter (default 10s, 60s, 300s), timeout worker lebih pendek dari retry_after. Audit key dan destination disanitasi sebelum log.

### Operasi file (TASK-003)

- Development: `docker compose build api`, `docker compose run --rm --no-deps minio-init`, `docker compose run --rm migrate`, lalu `docker compose up -d --wait`. Perintah init juga memperketat policy bucket lama. Sediakan RAM untuk database signature ClamAV (disarankan 4 GiB untuk scanner); jangan publish port 3310. Mount signature volume dan izinkan outbound FreshClam. Pantau health, usia signature dan pembaruan image.
- Produksi: pisahkan bucket, batasi principal aplikasi ke kedua bucket, blokir semua public access untuk bucket privat, izinkan hanya `GetObject` pada `published/*` di bucket publik; jangan izinkan listing anonim. `S3_DOWNLOAD_ENDPOINT` adalah origin S3 yang dapat dicapai browser (HTTPS di produksi), bukan URL bucket. Host/port/path tidak boleh diganti sesudah penandatanganan.
- Scheduler mengklaim sampai 25 baris per menit (`files:dispatch-scans`); Horizon memindai di antrean `default`. Cek `SELECT status,count(*),min(created_at) FROM stored_files WHERE status IN ('QUARANTINED','SCANNING') GROUP BY status`. Bila backlog bertambah, cek log ClamAV, Redis dan Horizon. Setelah layanan pulih, lease 10 menit yang lewat dikirim ulang; jangan mengubah status ke READY secara manual.
- `files:prune` harian membersihkan objek privat tak terpakai berumur >24 jam di `quarantine/` dan `ready/`, termasuk sisa dari transaksi yang batal. Tidak menyentuh objek aktif maupun prefix lain. Jangan pasang lifecycle berbasis umur saja pada prefix aktif.

## Pipeline dan release

**Operasi TASK-002:** jalankan `docker compose run --rm migrate` sebelum worker/scheduler memakai skema baru, lalu `docker compose restart horizon scheduler`. Cek `docker compose exec api php artisan schedule:list` dan `docker compose exec api php artisan horizon:status`. Relay dan reconciler berjalan tiap menit, hingga 100 klaim per proses. Event yang gagal enqueue dicoba ulang setelah lease satu menit; receipt yang hilang dari Redis dicoba ulang setelah 15 menit. Periksa backlog dengan `SELECT count(*), min(occurred_at) FROM outbox_events WHERE published_at IS NULL`.

Untuk receipt `FAILED`, gunakan `docker compose exec api php artisan queue:failed`. Periksa exception, event dan handler, perbaiki penyebab, lalu `docker compose exec api php artisan queue:retry <uuid>`. Reconciler tidak mengulang `FAILED` tanpa keputusan operator. Receipt `COMPLETED` membuat job duplikat menjadi no-op.

| Tahap | Gate |
|---|---|
| Pull request | Pint, Larastan, lint/typecheck kedua frontend, Pest PostgreSQL test, OpenAPI drift, dependency/image scan |
| Build | Build image API, admin dan storefront sekali, publish digest dan manifest; no build secret di layer akhir |
| Staging | Apply migration pada data sintetis, smoke login/checkout/worker/storage, E2E, concurrency, demo restore |
| Production preflight | Approve artifact, cek health dan kapasitas, ambil backup/checkpoint, cek kompatibilitas versi |
| Release | Expand migration → deploy API/workers/Nitro → refresh pods/container → smoke probes dan satu journey terkontrol → canary/monitor bila tersedia |
| Rollback | Jika runtime regression, redeploy digest sebelumnya yang kompatibel dengan expanded schema; pause affected jobs bila belum kompatibel. Jangan otomatis down-migrate data produksi. Destructive contract migration dipisah setelah data dan rollback window selesai. |

## Menjalankan TASK-006 dan alat operator lokal

- Setelah pull, jalankan `docker compose build`, `docker compose run --rm --no-deps api composer install --no-interaction`, `docker compose run --rm migrate`, lalu `docker compose up -d`. Jangan hapus volume. `web-deps` menjalankan `npm ci` saat lockfile/manifest atau versi Node berubah, sebelum admin dan storefront mulai.
- Untuk login Google, buat OAuth client **Web application**. Isi `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET` hanya pada environment runtime. Authorized redirect URI harus **persis** `APP_URL` ditambah `/api/v1/auth/google/callback`; contoh port 8080: `http://localhost:8080/api/v1/auth/google/callback`. Gunakan HTTPS di produksi. Email yang sama tidak cukup untuk menautkan akun: pemilik harus masuk dan membuktikan password; jika tidak punya password, reset lalu mulai ulang.
- OmniDB (`/db`) dan Log Viewer (`/log-viewerx`) hanya tersedia di host admin development melalui **Profil** setelah login superadmin + MFA. Alias storefront dihapus. Kredensial OmniDB hanya untuk login kedua di dalam OmniDB, bukan akses edge. Viewer hanya membaca log aplikasi; unduh dan hapus ditolak. Pertahankan binding loopback.

## Login staf dan superadmin pertama

Tidak ada akun atau password superadmin bawaan. Setelah pull, jalankan ini di direktori proyek (ganti email contoh):

```sh
docker compose run --rm migrate
docker compose up -d
docker compose restart horizon scheduler
docker compose exec api php artisan staff:invite nama@example.test --role=super_admin
```

Buka [Mailpit](http://localhost:8025), ikuti **Aktifkan akun staf**, lalu buat dan konfirmasi kata sandi. Pindai QR menggunakan aplikasi authenticator pada perangkat lain dan masukkan kode enam digit dari aplikasi. QR dibuat langsung di browser; jangan bagikan tangkapan layarnya. Simpan kode pemulihan yang hanya ditampilkan sekali. Buka [login admin](http://admin.localhost:8080/login) dan masuk dengan kata sandi serta **kode berikutnya**. Pastikan `ADMIN_URL` dan `SANCTUM_STATEFUL_DOMAINS` di environment sesuai host dan port. Undangan kedaluwarsa setelah satu jam; ulangi perintah dengan role yang sama untuk mengirim pengganti.

Untuk staf biasa gunakan `--role=admin`, atau undang melalui **Administrasi → Staff & Sesi**. Mutasi keamanan meminta password dan TOTP baru; kode pemulihan tidak berlaku untuk konfirmasi ini. SMTP selain Mailpit harus dikonfigurasi di produksi.

Saat deploy fitur baru, jalankan migrasi lalu `php artisan db:seed --class=PermissionSeeder --force` sebelum membuka traffic. Seeder permission ini tidak membuat user atau data demo. Bila nama `super_admin` sudah dipakai role non-sistem, seeder berhenti tanpa mempromosikan anggotanya: periksa anggota dan ubah nama role lama sebelum melanjutkan.

## Backup, restore dan disaster recovery

Baseline usulan RPO 24 jam dan RTO 4 jam. Snapshot/pg_dump konsisten harian dan backup sebelum migrasi risiko tinggi, repositori off-host terenkripsi, retention 14 harian dan 3 bulanan (ASSUMPTION). Simpan object versioning/replica dan manifest file keys, serta versi env/secret refs secara terpisah. Redis disposable untuk cache; outbox PostgreSQL dan idempotency ledger memungkinkan rekonstruksi queue kritis. Gunakan PITR jika target RPO lebih ketat. Private bucket backup tunduk pada kebijakan retensi dan akses yang sama.

- Isolasi aplikasi write dan worker; simpan bukti/log serta tentukan restore point bersama operator dan finance.
- Restore PostgreSQL, object version yang berkaitan dan key enkripsi dengan izin recovery di jaringan terisolasi.
- Validasi FK, ledger versus inventory balance, allocations versus payment/refund bank records, private object checksum/signed access dan smoke seluruh cara beli.
- Cocokkan transaksi manual/provider sejak restore point sebelum menerima traffic. Pulihkan antrean dari outbox/failed delivery dengan dedup dan aktifkan worker bertahap.
- Alihkan traffic setelah rekonsiliasi dan review; catat waktu RTO, data gap RPO dan tindakan follow-up. Uji restore tiap kuartal dan sebelum go-live.

[Arsitektur](03-TECH-STACK-ARCHITECTURE.md) · [Testing](09-TESTING-STRATEGY.md) · [Task tracker](11-TASK-PROGRESS-TRACKER.md)
