# Snowgo2 · Snowe GO

Snowgo2 adalah platform perdagangan untuk **Ready Stock, Open Preorder, dan Request Order**. Pembeli memilih barang atau mengajukan permintaan di storefront Snowe GO. Staf toko, finance, pengadaan, dan gudang menggunakan panel admin untuk menindaklanjuti transaksi, dari pesanan pertama sampai pengiriman atau pengembalian dana.

**Status: MVP dalam pengujian, belum dinyatakan siap produksi.** [Progres pengembangan](#progres-pengembangan) · [Panduan pembeli dan staf](docs/PANDUAN-OPERASIONAL-PEMBELIAN.md) · [Jalankan lokal](#jalankan-lokal) · [Deploy dengan Dokploy](docs/DEPLOY-DOKPLOY.md)

## Tiga cara membeli

| Skema | Kapan barang atau kuota ditahan? | Apa yang terjadi berikutnya? |
|---|---|---|
| **Ready Stock** | Checkout yang berhasil menahan unit di gudang; menambahkan barang ke keranjang tidak menahan stok. | Pembeli memilih ongkir saat checkout. Setelah finance memverifikasi transfer, unit dialokasikan. Stok fisik baru berkurang ketika gudang menyerahkan paket ke kurir. |
| **Open Preorder** | Checkout menahan kuota varian kampanye, bukan stok fisik. Dua pembeli tidak bisa mengambil slot terakhir yang sama. | Pembayaran awal terverifikasi mengonfirmasi slot. DP bukan pelunasan. Pengadaan mencatat unit yang diterima atau gagal; sisa harga dan ongkir ditagih sesuai unit yang bisa dikirim. |
| **Request Order** | Pengajuan dan percakapan belum membuat pesanan. Pesanan dan invoice awal baru terbit saat pembeli menerima penawaran yang masih berlaku. | Staf mengadakan barang sesuai permintaan. Pembeli dapat meminta revisi penawaran; ongkir domestik diajukan saat paket bisa ditimbang, bukan berdasarkan estimasi awal. |

Ketiganya memakai **transfer bank manual**. Mengunggah bukti tidak mengubah invoice menjadi lunas. Finance mencocokkan mutasi bank; transfer terlambat atau salah nominal masuk rekonsiliasi. [Lihat flowchart dan langkah di aplikasi](docs/PANDUAN-OPERASIONAL-PEMBELIAN.md).

## Fitur yang tersedia di `master`

| Area | Yang dapat dilakukan |
|---|---|
| Storefront dan akun | Jelajahi Shop dan Drops, cari/filter produk, lihat detail varian, simpan favorit, kelola profil dan alamat. Tersedia registrasi, verifikasi email, reset kata sandi, dan login Google bila dikonfigurasi. |
| Katalog dan konten | Kelola produk, kategori, varian/SKU, harga dan media melalui API. Editor konten admin mendukung draf, pratinjau, serta publikasi terjadwal untuk banner, FAQ, dan halaman kebijakan. Katalog publik mendukung SSR, metadata dan sitemap. |
| Keranjang dan pesanan | Lihat tagihan sebelum konfirmasi. Satu checkout campuran memisahkan barang siap kirim dan preorder menjadi pesanan dan invoice sesuai skemanya. Pembeli dapat melihat status, invoice, dan paket dari detail pesanan. |
| Inventori dan pengadaan | Catat saldo, penerimaan, penyesuaian, reservasi dan pergerakan stok per gudang. Kelola kampanye preorder melalui API staf; gunakan panel admin untuk memproses batch pengadaan, penerimaan parsial dan kekurangan pemasok. |
| Invoice dan pembayaran | Terbitkan invoice serta PDF privat, tagih DP atau sisa pembayaran, dan minta persetujuan pembeli atas ongkir atau biaya tambahan preorder/request. Finance memeriksa bukti, mencatat penerimaan, dan menangani selisih. |
| Ongkir dan pengiriman | Simpan wilayah, kurir, dan layanan secara lokal. Ambil tarif per rute dan berat dari API.co.id; jika tidak tersedia, gunakan tarif manual aktif yang cocok. Tanpa keduanya, ongkir tidak tersedia, bukan gratis. Gudang dapat membagi pesanan menjadi paket, mencatat resi, dan memperbarui pelacakan secara manual. |
| Retur dan refund | Pembeli mengajukan retur per paket. Staf memeriksa bukti dan kondisi barang untuk menentukan penggantian, perbaikan, pengembalian stok, atau refund. Refund dibatasi dana yang sudah diterima; penyetuju dan pelaksana transfer adalah orang berbeda. Aplikasi mencatat transfer balik, bukan mengirim uang dari bank. |
| Admin dan laporan | Dashboard menampilkan antrean, stok menipis, dan ringkasan transaksi. Laporan finance menghitung **penerimaan terverifikasi dikurangi refund yang sudah ditransfer**, bukan laba. Staf berizin dapat melihat audit, mengelola pengguna/role, dan mengatur toko. |

## Progres pengembangan

**Per 26 November 2026: 32 dari 46 task selesai, 2 dalam pengujian, dan 12 belum dimulai.** Ini hitungan task, bukan persentase kesiapan produksi.

| Status | Cakupan |
|---|---|
| Selesai · TASK-001–032 | Fondasi, identitas, katalog, stok, tiga jalur transaksi, operasi staf, konten dan pencarian. Di [snapshot tracker](https://github.com/FathaAdillah/Snowgo2/blob/69e4776e02169d0f69df82344074ec5aa9d2d85f/docs/11-TASK-PROGRESS-TRACKER.md), lima task operasional masih tertulis 75%. Hitungan di atas memasukkan implementasi yang sudah digabung di [PR #47](https://github.com/FathaAdillah/Snowgo2/pull/47); [PR #48](https://github.com/FathaAdillah/Snowgo2/pull/48) menyelaraskan centangnya. |
| Dalam pengujian · TASK-033–034 | UI akun, katalog, checkout, dan purnajual sudah ada. Yang belum ditutup: verifikasi visual desktop/ponsel serta perjalanan pembeli dengan keyboard di browser. |
| Belum dimulai · TASK-000, 035–039 | Persetujuan keputusan bisnis; notifikasi transaksi dan inbox; pemeriksaan keamanan/aksesibilitas dan uji beban menyeluruh; latihan pemulihan di staging serta persetujuan go-live. Adanya runbook deploy belum menutup pekerjaan staging. |
| Direncanakan · TASK-040–045 | Promosi/kupon, payment gateway, pelacakan kurir otomatis dan integrasi Shopee, keranjang tamu, transfer stok antargudang, serta laporan lanjutan. **Dashboard penerimaan/piutang dan laporan laba rugi berbasis HPP serta biaya operasional belum tersedia.** |

Lihat [tracker terkini beserta kriteria selesai per task](docs/11-TASK-PROGRESS-TRACKER.md) dan [keputusan bisnis yang masih terbuka](docs/01-BRD.md#asumsi-dan-pertanyaan-terbuka).

## Jalankan lokal

Dari root repo, gunakan Docker dengan Compose v2.24+, Make, dan OpenSSL. PHP dan Node berjalan di container.

```sh
make setup   # Siapkan env lokal, dependensi, image, dan migrasi
make up      # Nyalakan stack dan tunggu service sehat
make ps      # Lihat status container
```

Buka [storefront lokal](http://localhost) dan [admin lokal](http://admin.localhost). Tidak ada password superadmin bawaan. Ikuti [aktivasi staf pertama](docs/10-DEPLOYMENT.md#login-staf-dan-superadmin-pertama) untuk undangan email dan MFA. Gunakan port yang sesuai dengan env lokalmu. Untuk berhenti tanpa menghapus data, jalankan `make down`.

## Arsitektur dan pengembangan

| Komponen | Peran |
|---|---|
| [API](apps/api) | Laravel 13 / PHP 8.4, modular monolith dengan PostgreSQL 16. Redis, Horizon, dan scheduler menjalankan pekerjaan latar belakang. Audit journal, transactional outbox dan idempotensi menjaga jejak perubahan dan mencegah pemrosesan ulang transaksi yang sama. |
| [Admin](apps/admin) dan [storefront](apps/storefront) | Nuxt 4: panel staf dengan Nuxt UI dan storefront SSR. Browser mengakses API melalui proxy Nitro pada origin aplikasi; Laravel tetap memeriksa izin. |
| [Kontrak API](apps/api/api.json) dan [tipe TypeScript](packages/api-types) | OpenAPI v1 menjadi kontrak bersama kedua frontend. Setelah mengubah API, jalankan `make migrate` lalu `make contracts`; sertakan hasil generasi dalam PR, jangan edit manual. |
| [Infrastruktur](infrastructure) | Nginx sebagai pintu masuk, object storage kompatibel S3 untuk media dan berkas privat, serta ClamAV untuk memindai unggahan. MinIO dan Mailpit tersedia untuk pengembangan lokal. |

Untuk memeriksa perubahan, jalankan `make test`, `make lint`, `make contracts-check`, dan `make test-contract`. [CI](.github/workflows/ci.yml) memeriksa kontrak, menjalankan pengujian dan audit dependensi, lalu membangun dan memindai tiga image produksi. CI tidak memublikasikan image atau melakukan deploy.

## Keamanan dan operasi

- **Akses staf:** login dan MFA tetap wajib, termasuk untuk superadmin. Setiap fitur admin baru membutuhkan seeder izin per aksi; menu dan tombol mengikuti izin, dan otorisasi tetap ditegakkan di API. Konsol DB, Log Viewer, dan dashboard Horizon tidak diekspos di produksi.
- **Data dan kredensial:** simpan secret hanya di environment; key penyedia ongkir tidak dikirim ke browser. Jangan lampirkan `.env`, bukti transfer, data pelanggan, atau log berisi kredensial di issue maupun PR. Gunakan data sintetis saat melaporkan masalah.
- **Deployment:** gunakan [panduan Dokploy](docs/DEPLOY-DOKPLOY.md), [template env produksi](.env.production.example), dan [Compose produksi](docker-compose.prod.yml). Panduan mencakup registry, domain HTTPS, S3, SMTP, migrasi, seeder izin, pemeriksaan kesehatan, dan rollback.

**Lisensi:** [manifest API](apps/api/composer.json) menyatakan `proprietary`. Tidak ada lisensi open-source di root repositori; jangan mengasumsikan izin redistribusi dari akses baca saja.

[Indeks dokumentasi](docs/README.md) · [Matriks menu dan izin](docs/07-ADMIN-MENU-MATRIX.md) · [Kebijakan keamanan](docs/08-SECURITY.md) · [Strategi pengujian](docs/09-TESTING-STRATEGY.md)
