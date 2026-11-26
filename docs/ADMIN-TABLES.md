# Pola tabel admin

Gunakan [tabel Pengguna (`/staff`)](../apps/admin/app/pages/staff.vue) sebagai acuan saat membuat atau merevisi halaman daftar. Pertahankan susunan, tema, dan perilaku navigasinya; pilih isi tabel berdasarkan pekerjaan pengguna, bukan berdasarkan kolom staf.

## Pola dasar

| Bagian | Acuan untuk tabel admin |
|---|---|
| Kepala halaman | Judul dan deskripsi singkat di kiri. Jika fitur mengizinkan pembuatan data, tampilkan `UButton` **Tambah …** dengan ikon plus di kanan. Gunakan warna `primary` bawaan tema, bukan `success` atau warna tetap. |
| Pencarian dan filter | Tempatkan `UInput` pencarian dan filter yang relevan (misalnya `USelect` status) di atas `UTable`, dalam satu `UCard`. Beri label sesuai entitas. Biarkan kontrol turun baris pada layar sempit. |
| Baris | Gunakan ID record yang stabil, kolom identitas utama di awal, dan **Aksi** di akhir bila diperlukan. Beri teks pada `UBadge` agar status tetap jelas tanpa mengandalkan warna. |
| Tombol aksi | Gunakan [`AdminRowAction`](../apps/admin/app/components/AdminRowAction.vue) untuk setiap aksi per baris atau per kartu data. Hanya ikon lokal dalam tombol persegi berbingkai, tanpa tulisan. `label` (aksi + target) menjadi tooltip saat diarahkan atau difokuskan dengan keyboard, sekaligus nama untuk pembaca layar. Jangan hanya memakai `title`. |
| Pagination | Letakkan `UPagination` di kanan bawah: **« ‹ 1 › »** (pertama, sebelumnya, nomor halaman, berikutnya, terakhir). Nomor aktif memakai `primary` bawaan tema; panah netral dan nonaktif di batas halaman. Untuk daftar berpaginasi, tampilkan juga saat hasilnya satu halaman, tetapi tidak saat kosong atau gagal dimuat. |

## Contoh aksi per baris

```vue
<AdminRowAction
  icon="i-lucide-eye"
  :label="`Lihat pesanan ${item.number}`"
  :to="`/orders/${item.number}`"
/>
```

Pakai mata untuk detail, pensil untuk edit, kotak arsip untuk arsipkan, dan kotak dengan panah untuk aktifkan kembali. Pakai warna `error` untuk aksi berisiko dan pertahankan dialog konfirmasinya. Tombol utama halaman dan formulir (**Tambah**, **Simpan**, **Batal**, konfirmasi) tetap memakai teks.

Di layar sentuh, ketuk ikon sekali untuk membaca tooltip, lalu ketuk ikon yang sama lagi untuk menjalankan aksi. Dengan mouse atau keyboard, aksi tetap berjalan dalam satu klik atau aktivasi.

## Data dan izin

- Untuk daftar berpaginasi, kirim pencarian, filter, dan halaman ke API; jangan mengunduh semua baris untuk memfilter di browser. Ikuti [contoh filter staf](../apps/admin/app/composables/useStaffFilters.ts): debounce pencarian 300 ms, kembalikan ke halaman pertama saat kriteria berubah, dan pertahankan filter saat berpindah halaman. Gunakan total dan ukuran halaman dari respons, bukan jumlah baris yang sedang tampil.
- Bedakan **memuat**, **tidak ada hasil**, dan **gagal memuat**. Sediakan **Coba lagi** saat gagal. Jangan tunda daftar untuk memuat pilihan yang hanya diperlukan di formulir; misalnya, daftar role baru diminta saat form staf dibuka.
- Tampilkan aksi hanya jika permission **dan** keadaan record mengizinkan; API tetap memeriksa keduanya. Ikuti [aturan seeder dan izin per aksi](07-ADMIN-MENU-MATRIX.md). Daftar baca-saja tidak membutuhkan tombol tambah, edit, atau hapus.

## Menyesuaikan untuk fitur lain

Pada tabel staf, avatar inisial, badge role, dan penanda **ANDA** membantu mengenali orang. Ikon tempat sampah di sini berarti **Nonaktifkan**, bukan hapus permanen. Ini perilaku khusus staf, bukan kolom atau aksi wajib untuk tabel lain.

Misalnya, pada daftar pesanan gunakan nomor pesanan sebagai identitas dan filter status pesanan. Jika operator hanya boleh melihat detail, sediakan aksi **Lihat pesanan**, bukan tombol edit atau hapus. Tambahkan kolom, filter, atau aksi baru hanya jika kontrak API dan izin fitur mendukungnya.

Saat meninjau tabel baru, periksa tema terang/gelap, label tombol ikon, hasil kosong/satu/banyak halaman, filter dari halaman kedua, serta tampilan pengguna tanpa izin mutasi. [Tes tabel staf](../apps/admin/tests/staff-actions.test.mjs) memberi contoh perilaku yang bisa diuji ulang.
