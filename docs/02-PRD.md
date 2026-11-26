# Product Requirements Document

## Visi dan pengalaman

Pembeli dapat memilih cara beli berdasarkan ketersediaan dan komitmen, mengetahui tagihan yang berlaku, lalu melacak barang dan menyelesaikan masalah dalam satu akun. Staff melihat pekerjaan per permission dan menyelesaikan antrean tanpa mengubah status bebas.

| Persona | Journey |
|---|---|
| Pengunjung | Homepage → kategori/search → detail dengan label Siap Kirim atau Preorder, atau halaman Request Produk |
| Pembeli Siap Kirim | Keranjang → alamat dan layanan → transfer → bukti → status order → tracking → selesai |
| Pembeli PO | Kampanye → qty dan termin → checkout → bayar → ETA/batch → lunasi tagihan → paket |
| Pembeli Request | Form request + lampiran → diskusi → quotation → accept → transfer → pengadaan → paket |
| Pembeli after-sales | Order → refund/return → bukti → keputusan dan histori |
| Staff | Sign-in MFA → antrean sesuai permission → aksi domain → hasil dan audit |

Role, menu dan guard di [Admin Menu Matrix](07-ADMIN-MENU-MATRIX.md). Storefront mencakup homepage, daftar kategori/produk, pencarian, detail, cart, checkout, pembayaran dan invoice, pesanan, tracking, preorder, request order, refund, return, wishlist, profil, alamat dan auth. UI menyatakan cara beli, ETA dan biaya yang masih estimasi, mempertahankan form saat error, serta menampilkan loading, empty dan error state.

## Functional requirements

P0 menjaga jalur rilis atau integritas. P1 diperlukan untuk operasi dan pengalaman MVP. P2 masuk Phase 2. P3 dipakai untuk perluasan Future. Setiap baris menyatakan user story melalui aktor, tujuan dan alur.

| ID | Deskripsi | Aktor | Prasyarat | Alur utama | Alternatif | Pengecualian | Acceptance criteria | Prioritas | Rilis |
|---|---|---|---|---|---|---|---|---|---|
| FR-AUTH-001 | Identitas | Pembeli atau staff | Email tersedia dan aktor memenuhi syarat | Daftar, verifikasi, login dan kelola sesi | Google untuk pembeli, TOTP untuk staff | Rate limit, akun nonaktif, atau identitas konflik | Login menerbitkan sesi untuk permukaan akun yang tepat dan revoke efektif segera | P0 | MVP |
| FR-CUS-001 | Akun dan alamat | Pembeli | Sesi pembeli | Kelola profil, wishlist, dan alamat sendiri | Tambah alamat dan ubah alamat default | Alamat milik akun lain ditolak | Pesanan lama mempertahankan alamat snapshot | P1 | MVP |
| FR-GEO-001 | Data wilayah dan referensi | Staff operasional | Permission master data | Impor wilayah dan kelola kode alasan, unit, dan kelas pajak | Nonaktifkan kode yang masih dipakai histori | Kode wilayah yatim ditolak | Alamat tervalidasi terhadap hierarki wilayah aktif | P1 | MVP |
| FR-CAT-001 | Katalog, varian dan media | Product manager | Permission katalog | Kelola produk, kategori, brand, koleksi, tag, atribut, SKU, media, harga dan SEO | Produk draft atau arsip | SKU atau slug bentrok ditolak | Web hanya menampilkan produk terbit dan menyatakan cara beli per penawaran | P0 | MVP |
| FR-INV-001 | Gudang, stok dan ledger | Warehouse atau inventory staff | Gudang dan SKU aktif | Catat penerimaan dan adjustment, pantau saldo dan reservasi | Stock rendah masuk daftar kerja | Adjustment yang melampaui available ditolak | Saldo konsisten dengan movement dan basis stok fisik | P0 | MVP |
| FR-RTS-001 | Siap kirim | Pembeli dan warehouse | SKU siap kirim dan stok tersedia | Checkout memesan stok, dana lunas mengikat unit, paket diserahkan ke kurir | Reservasi dilepas saat kedaluwarsa | Checkout kehilangan stok mendapat konflik yang dapat dipulihkan | Dua checkout tidak bisa memperoleh unit fisik yang sama | P0 | MVP |
| FR-PRE-001 | Preorder | Pembeli dan order staff | Kampanye aktif dalam periode | Beli kuota, bayar DP/penuh, terima ETA dan tagihan lanjutan | Perpanjang kampanye atau kirim sebagian | Supplier gagal atau kuota habis | Kuota tidak mengurangi stok RTS dan kegagalan dapat diproses untuk refund | P0 | MVP |
| FR-PRC-001 | Pengadaan | Order staff | Alokasi PO atau order request telah dikonfirmasi | Kelompokkan PO, catat tahapan pembelian hingga unit diterima | Request dapat diproses satu order per batch | Unit gagal diadakan dicatat dan ditangani sebagian | Unit yang sudah dibayar dan diterima dapat lanjut tanpa menunggu anggota batch lain | P0 | MVP |
| FR-REQ-001 | Request order | Pembeli dan order staff | Sesi atau permission sesuai aksi | Ajukan rincian, diskusi, kirim penawaran, accept dan konversi | Revisi atau tolak penawaran | Penawaran kedaluwarsa atau versi diganti | Satu request melahirkan paling banyak satu order | P0 | MVP |
| FR-CRT-001 | Keranjang | Pembeli | Sesi pembeli | Tambahkan varian RTS atau penawaran PO, edit qty dan lihat estimasi | Hapus item yang berubah ketersediaannya | Penawaran tidak lagi bisa dibeli ditandai | Mengubah cart tidak mengubah inventory atau kuota | P0 | MVP |
| FR-CHK-001 | Checkout | Pembeli | Cart valid dan alamat aktif | Preview total, pilih layanan RTS, konfirmasi dan buat order | Satu order RTS dan satu per kampanye dalam checkout gabungan | Harga, qty atau quote berubah, minta review ulang | Retry intent yang sama memberi hasil sama; kegagalan satu grup membatalkan semuanya | P0 | MVP |
| FR-ORD-001 | Order dan penyelesaian | Pembeli dan order staff | Order milik aktor atau staff berizin | Baca histori, proses fulfillment, konfirmasi penerimaan | Batalkan sebelum dana masuk atau ajukan review setelah bayar | Transisi invalid ditolak | Histori dan snapshot tetap dapat diaudit | P0 | MVP |
| FR-IVC-001 | Invoice | Pembeli dan finance | Kewajiban tagih teridentifikasi | Terbitkan DP, pelunasan, penuh atau biaya tambahan dan PDF | Void invoice belum dibayar lalu terbitkan revisi | PDF gagal dapat dicoba ulang | Nominal invoice issued tidak dapat diubah | P0 | MVP |
| FR-PAY-001 | Transfer manual | Pembeli dan finance | Invoice terbit dan rekening aktif | Buat payment intent, unggah bukti, cocokkan transfer, approve atau reject | Verifikasi terlambat masuk recovery berizin | Verifikasi ulang atau referensi bank duplikat tidak menambah dana | Alokasi dana dan perubahan order terjadi atomik | P0 | MVP |
| FR-SHP-001 | Pengiriman dan tracking | Warehouse, pembeli dan provider | Unit siap dan kewajiban lunas | Buat paket, input jasa dan resi, kirim, tampilkan histori | Satu order dapat dikirim beberapa paket, update manual saat provider gagal | Webhook duplikat atau out-of-order tidak menurunkan status | Kuantitas kirim tidak melebihi unit layak, histori dapat ditelusuri | P0 | MVP |
| FR-RFD-001 | Refund | Pembeli dan finance | Ada dana diterima untuk order | Ajukan, review, setujui, transfer dan catat bukti | Refund penuh, sebagian atau per item | Tolak nominal yang melampaui dana tersedia | Konfirmasi transfer satu kali dengan audit dan rekonsiliasi | P0 | MVP |
| FR-RTN-001 | Return | Pembeli dan customer service | Paket delivered dan dalam jendela pengajuan | Ajukan bukti, review, instruksi, terima dan inspeksi | Resolusi replacement, repair, kirim kekurangan atau refund | Pengajuan ditolak atau barang gagal inspeksi | Qty yang disengketakan tidak melebihi qty delivered dan resolusi terdokumentasi | P0 | MVP |
| FR-PRM-001 | Promo dan voucher | Marketing dan pembeli | Promo aktif dan keranjang eligible | Hitung diskon deterministik serta hold penggunaan saat checkout | Lepas kuota jika order batal sebelum bayar | Kuota global atau per akun habis | Kuota final tidak terlampaui secara concurrent | P2 | Phase 2 |
| FR-CNT-001 | Konten toko | Content manager | Permission kelola konten | Terbitkan homepage, banner, FAQ dan kebijakan | Pratinjau draft dan jadwalkan publikasi | Slug atau referensi media invalid ditolak | Publik hanya melihat versi terbit yang efektif | P1 | MVP |
| FR-NTF-001 | Notifikasi | Pembeli dan staff | Commit domain event atau challenge identitas | Tulis inbox dan kirim email async | Ulang proses delivery gagal dari antrean | Provider SMTP outage tidak membatalkan commit bisnis | Notifikasi in-app tidak duplikat dan email dilacak per delivery | P1 | MVP |
| FR-SRC-001 | Search dan SEO | Pengunjung | Katalog terbit | Cari, filter dan buka landing page terindeks | Query kosong memakai daftar katalog | Query terlalu panjang ditolak | Draft tidak ditemukan dan canonical/metadata tersedia | P1 | MVP |
| FR-RPT-001 | Dashboard dan laporan | Staff | Permission dashboard atau laporan | Baca order, penerimaan, shipment, refund, return dan low stock | Filter rentang tanggal atau ekspor agregat | Role tanpa scope mendapat 403 | Penerimaan bersih memakai dana terverifikasi dikurangi refund selesai, bukan laba | P1 | MVP |
| FR-RPT-002 | Dashboard Keuangan | Staff keuangan berizin | Sesi staff dan izin laporan keuangan | Bandingkan penerimaan terverifikasi, refund selesai dan penerimaan bersih per hari/bulan; pisahkan piutang belum jatuh tempo dan terlambat | Filter periode dan jalur jual RTS/PO/RO | Izin dashboard operasional saja tidak cukup | Nominal cocok dengan alokasi invoice; mutasi yang dikembalikan lewat rekonsiliasi tanpa capture bukan penjualan maupun refund atas penjualan | P1 | Phase 2 |
| FR-RPT-003 | Laba rugi operasional | Finance | Kebijakan pengakuan pendapatan dan HPP disetujui | Catat HPP per unit/batch dan biaya operasional; tampilkan pendapatan neto yang diakui dikurangi HPP sebagai laba kotor, lalu kurangi biaya operasional untuk laba operasional | Telusuri biaya dan koreksinya menurut periode | Modal kosong ditandai belum lengkap; izin baca tidak memberi izin ubah biaya | DP tidak otomatis menjadi pendapatan; retur dan refund tidak mengurangi hasil dua kali | P1 | Phase 2 |
| FR-ADM-001 | Administrasi | Staff berizin | MFA dan permission | Kelola staff, role, permission, konfigurasi dan audit | Revoke sesi dan review aktivitas | Pencabutan administrator terakhir ditolak | Perubahan sensitif memiliki actor dan audit record | P0 | MVP |
| FR-FND-001 | Platform dan operasi | Developer dan operator | Akses lingkungan sesuai tugas | Jalankan Compose, health, logs, CI dan kontrak API | Restore dan rollback sesuai prosedur | Service belum siap menghasilkan readiness 503 | Build dan deployment dapat diulang dan kegagalan terlihat | P0 | MVP |
| FR-SEC-001 | Kontrol keamanan dan aksesibilitas | Pembeli, staff dan operator | Kebijakan keamanan tersedia | Jalankan kontrol CSRF, policy, validasi file serta aksesibilitas | Akses file melalui URL sementara | Aktor lintas akun ditolak dan PII tidak tercatat di log | Operasi sensitif terlindungi dan keyboard journeys dapat diselesaikan | P0 | MVP |
| FR-EXT-001 | Ekstensi operasional | Staff dan pembeli | Lifecycle MVP stabil | Aktifkan gateway, Shopee, transfer dan guest cart secara bertahap | Override provider melalui konfigurasi | Provider gagal, tampilkan fallback manual bila berlaku | Ekstensi mempertahankan kontrak core dan ledger | P2 | Phase 2 |

## Non-functional requirements

| ID | Requirement | Acceptance awal |
|---|---|---|
| NFR-FND-001 | Reliability dan observability | API readiness 503 saat dependensi wajib gagal, request ID lintas proxy/job, metrik outbox lag dan rekonsiliasi; target MVP 99,5% per bulan (ASSUMPTION) |
| NFR-CHK-001 | Integritas pada persaingan | Pada 100 checkout paralel, hasil sukses sama dengan jumlah unit yang dialokasikan, tanpa saldo negatif atau order separuh |
| NFR-SRC-001 | Performance dan SEO | p95 API baca katalog ≤ 400 ms pada 50 RPS di staging dengan 10 ribu SKU; kondisi data terukur, LCP p75 target ≤ 2,5 detik pada template publik; canonical, sitemap, robots dan Product structured data cocok dengan fakta penawaran |
| NFR-SEC-001 | Security dan privacy | HTTPS, cookie aman, MFA staff, CSRF, ownership, audit, signed URLs privat. Checklist keamanan rilis dan test akses lintas akun lulus |
| NFR-SEC-002 | Aksesibilitas | Target WCAG 2.2 AA untuk journey inti, keyboard/fokus, label, error terhubung ke field, dan kontras diuji |
| NFR-OPS-001 | Recovery | Baseline RPO 24 jam dan RTO 4 jam (ASSUMPTION), dibuktikan dengan restore lingkungan terisolasi |
| NFR-NTF-001 | Event dan delivery | Event kritis bertahan setelah commit. Retry tidak menggandakan inbox, dan email memiliki delivery status serta jalur rekonsiliasi |

Reporting menghitung order per cara beli, nilai capture dan refund, antrean status, serta low stock. Data perilaku memakai event pengukuran checkout, produk dan search tanpa memuat PII. Dashboard dan email mengambil event atau read model yang terdokumentasi. SEO diterapkan pada halaman publik. Halaman akun, basket, dan checkout diberi noindex.

## Matriks notifikasi

| Trigger atau event | Penerima | Channel | Isi minimum |
|---|---|---|---|
| Pendaftaran dan EmailVerificationRequested | Customer | Email | Welcome ringkas, verifikasi dengan link berjangka |
| PasswordResetRequested | Pemilik email eligible | Email | Link reset berjangka dan instruksi keamanan |
| OrderCreated, InvoiceIssued | Pembeli | Email, inbox | Nomor order/tagihan, due date, link akun dan cara bayar |
| PaymentSubmitted | Pembeli dan antrean finance | Email/inbox pembeli, dashboard finance | Nomor payment, status menunggu review dan SLA |
| PaymentApproved, PaymentRejected, OrderExpired | Pembeli | Email, inbox | Keputusan, alasan customer-safe dan aksi berikutnya |
| PreorderConfirmed, PreorderEtaUpdated, ProcurementStageChanged | Pembeli yang terdampak | Email, inbox | Referensi PO, ETA/milestone dan perubahan kewajiban |
| RequestOrderSubmitted, QuotationSent/Responded/Accepted | Pembeli dan antrean staff | Email/inbox pembeli, dashboard staff | Status, nomor request/quotation dan valid_until bila relevan |
| ChargeProposed | Pembeli | Email, inbox | Nominal usulan dan link untuk keputusan |
| ShipmentStatusUpdated, OrderDelivered, OrderCompleted | Pembeli | Email, inbox | Status, resi dan langkah berikutnya; debounce update nonmaterial |
| RefundRequested/Approved/Rejected/Completed | Pembeli dan antrean finance | Email/inbox pembeli, dashboard finance | Keputusan, nominal yang relevan dan next step |
| ReturnRequested/Reviewed/Resolved | Pembeli dan antrean CS | Email/inbox pembeli, dashboard CS | Keputusan, instruksi, tenggat dan link kasus |

Email transaksi tidak bergantung pada opt-in marketing. Link menuju halaman akun atau challenge khusus, dan email tidak melampirkan dokumen privat. Template memakai locale id-ID, format rupiah dan waktu WIB. Perubahan housekeeping yang tidak memengaruhi pelanggan masuk audit/dashboard.

## Rencana rilis

- **MVP.** Seluruh baris MVP, termasuk tiga cara beli, gudang dan ledger, private files, invoice, settlement manual, antrean admin, tracking kurir manual dengan port integrasi, notifikasi dan after-sales. WhatsApp dan push belum menjadi target.
- **Phase 2.** Promo/coupon, gateway pembayaran, integrasi Shopee, guest cart dan merge, transfer stok, otomatisasi tracking provider ketika kontrak tersedia, laporan lanjutan, dan unique transfer code bila finance memerlukannya.
- **Future.** Search eksternal bila PostgreSQL mencapai batas, mobile, stock opname, WhatsApp/SMS/push, dan store credit.
