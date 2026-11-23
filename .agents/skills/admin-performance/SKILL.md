---
name: admin-performance
description: Ukur dan perbaiki dashboard Nuxt Snowgo2 yang lambat di Docker lokal. Gunakan untuk membedakan cold start, Vite, Nuxt UI, CDN, proxy, dan latensi Laravel.
---

# Profil waktu buka admin

1. Catat host/port edge, versi, jenis build, dan aksi pengguna (hard refresh, navigasi setelah login, atau buka ulang). Jangan gunakan profil browser, cookie, password, atau QR MFA pribadi. Jangan nonaktifkan route guard, MFA, atau pemeriksaan Spatie.
2. Pastikan stack lokal sudah berjalan. Periksa paket melalui `docker compose exec -T admin npm ls -w @snowego/admin @nuxt/ui --depth=0` **di mode Vite**. Dependency yang terpasang tidak membuktikan tidak ada request eksternal: cek juga origin JS, CSS, font dan ikon di Network.
3. Jalankan pengukur di **mesin yang membuka dashboard**, dengan Node dan Chrome. Contoh Git Bash/Linux, dari root repo (instalasi terisolasi, tidak mengubah lockfile):

   ```sh
   bench=$(mktemp -d)
   cp .agents/skills/admin-performance/scripts/measure.mjs "$bench/"
   npm install --prefix "$bench" --no-save --ignore-scripts --no-audit --no-fund playwright-core@1.58.2
   node "$bench/measure.mjs" http://admin.localhost:8080 3 fixture
   node "$bench/measure.mjs" http://admin.localhost:8080 3 guest
   ```

   Ganti port sesuai edge. Jika Chrome tidak terdeteksi, set `CHROME_PATH` ke executable Chrome/Edge. `fixture` mengganti respons API hanya di browser sementara: mengukur hingga **Ringkasan Penjualan** terlihat, bukan kecepatan database atau sesi nyata. `guest` tidak mengganti respons apa pun dan mengukur redirect dari `/` hingga form login tampil. Tidak ada sesi yang dibuat oleh fixture di Laravel.
4. Pisahkan sampel pertama setelah restart dari median saat server hangat. `fresh` berarti profil browser baru; `reload` memakai cache browser yang sama. `elapsedMs` juga mencakup reload internal Vite; `readyMs` berasal dari awal dokumen terakhir. Catat jumlah resource **selesai saat konten terlihat**, modul Reka terpisah, dan host eksternal. Jangan menjumlahkan durasi request yang berjalan paralel.
5. Ukur HTML dan API asli secara terpisah, contoh `curl --noproxy '*' -sS -o /dev/null -w '%{http_code} %{time_starttransfer} %{time_total}\n' http://admin.localhost:8080/api/v1/health`. Bandingkan dengan `/`, `/api/v1/admin/auth/me` (tamu: 401), dan `/api/v1/admin/dashboard`. Selama API-RPT-001 belum ada, 404 mengaktifkan data contoh. Untuk sesi staf, gunakan **durasi/status saja** dari Network atau log edge. Jangan ekspor HAR berisi cookie, header, query string atau payload.
6. Uji perubahan tunggal. Bila Reka menghasilkan ratusan modul, periksa bahwa prebundle Vite aktif; jangan mengganti Nuxt UI dengan CDN. Bandingkan juga dengan [bundle lokal tanpa HMR](../../../docs/10-DEPLOYMENT.md#admin-lokal-cepat-tanpa-hot-reload), melalui edge dan port yang sama. Jika `/auth/me` yang lambat, pisahkan waktu Nitro dan `api-nginx` sebelum mengubah Laravel; jangan cache identitas/izin di browser untuk mempercepat guard.
7. Laporkan angka sebelum/sesudah beserta mode, jumlah sampel dan batas pengukuran. Verifikasi lint, typecheck, tes admin, production build, dan bahwa tamu tetap ditolak oleh API/alat operator. Nyatakan mode yang ditinggalkan di PC pengguna dan cara kembali ke HMR.
