# Deploy Snowgo2 lewat Dokploy

Gunakan resource **Docker Compose**, sumber Git, dan [`docker-compose.prod.yml`](../docker-compose.prod.yml). Jangan gunakan Docker Stack, Compose development, atau `make setup`. Rilis ini memakai tiga image yang **sudah ada di registry**; Dokploy tidak perlu memasang PHP, Composer, atau Node di host.

**Urutan rilis:** publikasikan image → siapkan env dan ingress → hidupkan PostgreSQL/Redis → migrasi → seed izin → jalankan aplikasi → uji lewat HTTPS. Pekerjaan belum selesai hanya karena halaman toko sudah tampil. Latih prosedur ini dulu di staging dengan DB, bucket, kredensial dan nama project terpisah.

## 1. Yang harus siap

| Siapkan | Periksa sebelum deploy |
|---|---|
| Host | Dokploy + Docker Engine + Compose v2.24 atau lebih baru pada host tujuan. Sediakan disk untuk image, database, Redis AOF dan signature ClamAV; anggarkan sekitar 4 GiB RAM untuk scanner **di luar** kebutuhan aplikasi. |
| DNS dan TLS | Dua hostname berbeda, misalnya `shop.example.org` dan `admin.example.org`, menuju Traefik Dokploy. Izinkan HTTP/HTTPS publik untuk redirect dan sertifikat; batasi panel/SSH untuk operator. Jangan publikasikan port PostgreSQL, Redis, ClamAV, PHP-FPM atau Nitro. |
| Registry | Pihak yang build punya akses push; host deployment punya akses pull. Simpan tiga digest rilis lama dan baru beserta commit sumber. Workflow CI repo **build dan scan, tidak push**. |
| Object storage | S3-compatible **eksternal**; Compose produksi tidak membawa MinIO maupun init bucket. Buat bucket katalog dan privat berbeda. Publik hanya boleh membaca `published/*` di bucket katalog, tanpa listing. Blokir akses anonim ke bucket privat dan buat objek kecil bernama **`.readiness`** di akarnya. Beri akun aplikasi akses terbatas ke kedua bucket; jangan gunakan root key. |
| Email | SMTP produksi dengan pengirim terverifikasi dan TLS (contoh port 587/STARTTLS); siapkan SPF/DKIM/DMARC. Undangan staf dikirim lewat email, bukan Mailpit. |
| Pemulihan | Backup PostgreSQL konsisten, versi objek/bucket dan cadangan secret terenkripsi di luar host. Pastikan backup bisa dipulihkan di lingkungan terpisah. Volume Docker bukan backup. |

## 2. Publikasikan tiga image dari commit yang sama

Di mesin build/CI tepercaya, checkout commit yang lolos pemeriksaan. Jalankan dari **root repo**, bukan dari direktori Dockerfile. Contoh shell POSIX ini menggunakan GHCR; ganti namespace dengan milikmu. Autentikasi registry lebih dahulu via credential helper atau `docker login --password-stdin`; jangan letakkan token dalam perintah atau log.

```sh
set -eu
REGISTRY_PATH=ghcr.io/organisasi/snowgo2
TAG=$(git rev-parse --verify HEAD)
PLATFORM=linux/amd64  # sesuaikan dengan arsitektur server Dokploy
for app in api admin storefront; do
  image="$REGISTRY_PATH/$app:$TAG"
  docker buildx build --platform "$PLATFORM" --target prod \
    -f "infrastructure/docker/$app/Dockerfile" -t "$image" --push .
  docker buildx imagetools inspect "$image"
done
```

Ambil `Digest:` tingkat teratas masing-masing hasil. Isi `SNOWEGO_API_IMAGE`, `SNOWEGO_ADMIN_IMAGE`, dan `SNOWEGO_STOREFRONT_IMAGE` dalam format `registry/namespace/image@sha256:…`. Image API yang sama dipakai oleh `api`, `horizon`, `scheduler`, dan `migrate`. Jangan pakai `latest` atau tag `:local` dari laptop.

## 3. Buat resource, isi Environment, pasang file Nginx

1. Di project/environment Dokploy pilih **Compose**, bukan Application atau Stack. Hubungkan repo Snowgo2, pilih branch rilis terkontrol, path `./docker-compose.prod.yml`. **Matikan AutoDeploy** sampai migration gate di bagian 4 sudah diotomatisasi. Hindari dua rilis bersamaan; jangan ubah nama project karena nama volume mengikutinya.
2. Buka **Environment**, salin [template env produksi](../.env.production.example) dan isi nilainya. [Template lokal](../.env.example) berisi password dummy, localhost dan Mailpit; jangan salin ke produksi. Dokploy menulis `.env` untuk interpolasi Compose. Tidak perlu `env_file: .env` di semua service: itu justru akan membagikan secret backend ke frontend.
3. Buat tiga **File Mounts** di **Advanced → Mounts**. Isi dari commit rilis yang sama. Di bawah direktori `files` milik resource, path-nya `nginx/edge.conf`, `nginx/edge-common.conf`, dan `nginx/api.conf`, sesuai [sumber Nginx](../infrastructure/nginx/). Set `NGINX_CONFIG_DIR=../files/nginx`. Pastikan ketiganya **file**, bukan direktori kosong. Dokploy dapat mengganti checkout Git ketika redeploy; jangan menaruh konfigurasi persisten di checkout. Saat config berubah, perbarui File Mounts sebelum deploy image terkait.
4. Pada salinan **`edge-common.conf` khusus produksi HTTPS** ubah `proxy_set_header X-Forwarded-Proto $scheme;` menjadi `proxy_set_header X-Forwarded-Proto https;`. Traefik mengakhiri TLS, sehingga koneksi internal ke edge memakai HTTP. Gunakan ini **hanya** bila seluruh trafik publik dipaksa HTTPS di Traefik dan edge tidak dapat diakses langsung dari internet. Jangan meneruskan header proto dari klien begitu saja. Jangan pasang snippet `admin-dev`.
5. Di **Domains**, daftarkan kedua hostname dengan **service `edge`, container port `80`, path `/`, HTTPS** dan sertifikat yang valid. Aktifkan/periksa redirect HTTP → HTTPS. Contoh template menggunakan `EDGE_BIND=127.0.0.1` dan `EDGE_HTTP_PORT=18080` (pilih port loopback kosong); ini **bukan** port tujuan domain. Jangan pakai host port 80/443 yang sudah dipakai Traefik.
6. Buka **Preview Compose**: Traefik harus bisa menjangkau `edge`, sementara `edge` tetap satu jaringan privat dengan kedua Nitro. Hanya `edge` yang mendapat route publik. Pastikan jaringan privat tetap menghubungkan Nitro → `api-nginx` → `api`, serta API/worker → DB, Redis, scanner. Jangan menyelesaikan masalah jaringan dengan memublikasikan semua port.

| Kelompok env | Nilai dan batasan |
|---|---|
| Origin dan sesi | `APP_URL`/`ADMIN_URL` = origin HTTPS persis, tanpa slash terakhir; `STOREFRONT_HOST`/`ADMIN_HOST` = hostname saja; `SANCTUM_STATEFUL_DOMAINS` = kedua hostname dipisahkan koma (tambahkan port jika bukan port standar). Isi `TRUSTED_PROXIES` sesuai IP/CIDR proxy privat yang dikelola, bukan `*`. Compose sudah menetapkan `APP_ENV=production`, debug mati dan secure cookie. |
| Secret | Buat `APP_KEY` **sekali**: awalan `base64:` diikuti hasil `openssl rand -base64 32`; lakukan di terminal privat dan simpan di secret manager. Gunakan key yang sama saat redeploy/restore. Buat password panjang berbeda untuk PostgreSQL dan Redis. Mengubah env saja tidak merotasi password DB pada volume yang sudah terisi. |
| S3 | `S3_ENDPOINT` harus dapat dijangkau aplikasi; `S3_DOWNLOAD_ENDPOINT` adalah origin HTTPS S3 yang dapat dijangkau browser (**bukan** URL bucket); `S3_PUBLIC_URL` adalah URL dasar media katalog. Cocokkan region dan path-style dengan penyedia. |
| SMTP dan integrasi | Isi `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, serta username/password jika relay memerlukannya. Opsional: OAuth Google dengan callback `APP_URL` + `/api/v1/auth/google/callback`; `API_CO_ID_KEY` hanya backend. Tanpa key ongkir, siapkan tarif manual aktif yang cocok. |

## 4. Perintah Docker dan urutan rilis di Dokploy

**Sebelum menulis ke DB:** ambil backup/checkpoint; untuk upgrade, pastikan migrasi kompatibel dengan kode lama. Jika tidak, aktifkan maintenance di ingress, hentikan penerimaan pekerjaan baru dan beri waktu worker menyelesaikan job, lalu hentikan `horizon` dan `scheduler`. Jangan buka trafik lagi sampai smoke test lulus. Untuk instalasi baru, batasi akses publik selama bootstrap.

Gunakan **Advanced → Command** dan jalankan **Deploy** satu tahap per kali. Simpan perintah asli Dokploy lebih dahulu. Kolom ini **mengganti seluruh perintah** dan Dokploy menambahkan kata `docker`. Pertahankan awalan `compose -p NAMA_PROJECT -f FILE` **persis seperti yang ditampilkan panel** (termasuk flag/path hasil render jika ada). Ganti hanya bagian setelahnya dengan baris berikut, tanpa `docker` tambahan atau `&&`. Tunggu sukses sebelum lanjut.

| Tahap | Akhiran perintah setelah `compose -p … -f …` |
|---|---|
| 1. Validasi tanpa mencetak secret | `--profile release config --quiet` |
| 2. Unduh image termasuk job rilis | `--profile release pull` |
| 3. Siapkan DB dan Redis; tunggu sehat | `up -d --wait postgres redis` |
| 4. Terapkan migrasi dengan image kandidat | `--profile release run --rm --no-deps migrate` |
| 5. Sinkronkan izin per aksi, bukan data demo | `--profile release run --rm --no-deps migrate php artisan db:seed --class=PermissionSeeder --force --no-interaction` |
| 6. Jalankan runtime melalui Dokploy | `up -d --wait` **tanpa** `--profile release` |

Contoh isi lengkap tahap 4 **jika** prefix panel adalah `compose -p snowgo2-prod -f docker-compose.prod.yml`: `compose -p snowgo2-prod -f docker-compose.prod.yml --profile release run --rm --no-deps migrate`. Tahap terakhir harus memakai Compose yang dirender Dokploy agar label domain dan jaringan Traefik ikut diterapkan. Kembalikan kolom Command ke default sesudahnya untuk menghindari menjalankan job migrasi saja pada rilis berikutnya; AutoDeploy tetap mati. Ulangi urutan ini saat mengganti digest. `restart` saja tidak mengganti image.

**Padanan CLI untuk preflight/job rilis.** Di shell **host Docker tujuan** (bukan console container), gunakan working directory, project, env, dan file Compose efektif yang **sama** dengan deployment Dokploy. Ambil nama/path dari panel atau log deployment; jangan menebak `-p snowego`. Contoh:

```sh
set -eu
: "${PROJECT:?isi nama project Compose dari Dokploy}"
: "${COMPOSE_FILE_PATH:?isi path Compose efektif dari deployment}"
: "${RELEASE_ENV:?isi path env rilis yang dilindungi}"
dc() { docker compose -p "$PROJECT" --env-file "$RELEASE_ENV" -f "$COMPOSE_FILE_PATH" "$@"; }
dc --profile release config --quiet
dc --profile release config --services
```

Untuk menjalankan tahap 2–5 via SSH, pakai `dc` diikuti akhiran pada tabel. Pilih **panel atau SSH**, bukan keduanya untuk job yang sama. Untuk pemeriksaan lokal sebelum upload, gunakan file kandidat dengan env privat dan `docker compose --env-file .env.production -f docker-compose.prod.yml --profile release config --quiet`; ini tidak memulai container dan tidak membuktikan kredensial benar. Jangan tempel hasil `config` penuh ke tiket. **Jangan gunakan** `make fresh`, `migrate:fresh`, `make seed`, atau `down -v` di produksi.

## 5. Verifikasi sebelum membuka toko

Di shell host yang sama, jalankan:

```sh
dc ps -a
dc exec -T api php artisan migrate:status
dc exec -T api php artisan horizon:status
dc exec -T api php artisan schedule:list
dc exec -T api-nginx wget -qO- http://127.0.0.1:8000/api/v1/internal/readiness
```

`migrate` adalah job sekali jalan, bukan daemon. Horizon harus aktif; hanya satu scheduler untuk stack ini. Readiness internal harus melaporkan **database, Redis dan storage `ok`**. `503` bukan sukses walaupun FPM atau halaman web hidup. Cek komponen yang gagal sebelum membuka trafik.

```sh
# Dari luar jaringan privat; ganti kedua hostname dengan domain yang dipasang.
curl -fsS https://shop.example.org/healthz
curl -fsS https://admin.example.org/healthz
curl -fsS https://shop.example.org/api/v1/health
curl -sS -o /dev/null -w '%{http_code}\n' https://shop.example.org/api/v1/internal/readiness
curl -sS -o /dev/null -w '%{http_code}\n' https://admin.example.org/horizon
```

Tiga probe pertama harus sukses, dua terakhir harus `404`. Verifikasi juga `/db` dan `/log-viewerx` tidak publik; produksi tidak mengekspos alat development. Untuk instalasi pertama, undang pemilik toko melalui `dc exec api php artisan staff:invite EMAIL_OPERATOR --role=super_admin`. Buka email produksi, aktifkan akun, daftarkan TOTP, dan uji login lewat host admin. Tidak ada password bawaan. Untuk staf lama, tinjau grant di **Role & Izin**; seeder tidak menimpa penugasan kustom.

Sebelum menerima transaksi, uji storefront dan login admin melalui HTTPS, email undangan, unggah → scan → unduh berkas privat dengan tautan bertanda tangan, serta ongkir pada satu rute. Uji alur tulis dengan data sintetis di staging, bukan dengan membuat transaksi pelanggan palsu di produksi. [Aktivasi fitur dan master bisnis](10-DEPLOYMENT.md#mengaktifkan-ongkir-apicoid).

## 6. Jika gagal atau harus rollback

| Gejala | Tindakan pertama |
|---|---|
| `pull` ditolak atau digest tidak ditemukan | Periksa namespace, digest, platform image, dan izin pull di host target. Jangan mengganti ke `latest`. |
| 502 atau sertifikat/host keliru | Periksa kedua domain menuju `edge:80`, jaringan hasil Preview, isi tiga File Mounts, redirect HTTPS dan `dc logs --tail=80 edge api-nginx`. |
| Admin `403`/`419` | Cocokkan origin admin, Sanctum, HTTPS dan cookie Secure; uji dari browser same-origin. Jangan matikan CSRF atau MFA. |
| Readiness `503`, email atau scan tertahan | Cek DB/migration, Redis, izin S3 dan objek `.readiness`; lalu status Horizon, scheduler, SMTP dan signature ClamAV. Jangan menandai file `READY` secara manual. |
| Migrasi atau seeder gagal | Hentikan rilis; jangan lanjut ke tahap runtime. Identifikasi DB/project, versi skema, dan penyebab sebelum mengulang. Jangan hapus volume atau jalankan rollback migrasi otomatis. |

Jika masalahnya pada kode, kembalikan **ketiga digest**, versi File Mounts dan konfigurasi yang cocok, lalu deploy ulang runtime melalui Dokploy **hanya jika skema masih kompatibel**. Periksa readiness dan login lagi. Jika data/skema tidak kompatibel, tahan trafik dan worker; pilih perbaikan maju atau restore terkoordinasi PostgreSQL + objek + key dari titik yang konsisten. Jangan menimpa transaksi baru tanpa rekonsiliasi finance. [Backup dan pemulihan](10-DEPLOYMENT.md#backup-restore-dan-disaster-recovery).

Referensi resmi: [Dokploy Docker Compose, Environment, Command dan File Mounts](https://docs.dokploy.com/docs/core/docker-compose) · [Domains dan Preview Compose](https://docs.dokploy.com/docs/core/docker-compose/domains).
