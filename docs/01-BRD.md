# Business Requirements Document

## Ringkasan, latar belakang, dan masalah

Snowe GO melayani pembelian barang siap kirim di Indonesia serta personal shopping dari luar negeri melalui preorder/group order dan request produk. Pelanggan membutuhkan kejelasan tentang ketersediaan, tenggat, biaya akhir, bukti bayar, status pengadaan, dan penyelesaian masalah. Tim membutuhkan satu tempat untuk merekonsiliasi pembayaran, mengendalikan stok dan kuota, serta menangani pesanan dan pengecualian.

## Tujuan dan ruang lingkup

Sediakan toko satu merchant dengan tiga cara pembelian, jalur operasi admin, dan riwayat transaksi yang dapat diperiksa dari awal sampai selesai. MVP melayani pelanggan Indonesia dalam IDR dan transfer bank manual. Pengadaan barang luar negeri dikelola operator. Integrasi marketplace, gateway, dan promo menyusul setelah jalur dasar stabil.

| Pemangku kepentingan | Kebutuhan |
|---|---|
| Pemilik dan product owner | Penjualan terlihat, kebijakan disetujui, biaya dan risiko terkendali |
| Pembeli barang ready stock | Ketersediaan yang dapat dipercaya, ongkir, resi dan ETA domestik |
| Kolektor dan peserta group order | Batas waktu, kuota, DP, pembaruan ETA, pelunasan dan biaya tambahan |
| Pembeli personal shopping | Diskusi pilihan barang, persetujuan quotation dan status pengadaan |
| Tim katalog, order, gudang dan CS | Data produk, paket dan pengecualian yang dapat ditindaklanjuti |
| Finance | Instruksi transfer, rekonsiliasi, bukti dan refund yang dapat diaudit |
| Operator teknis | Rilis, pemantauan dan recovery yang dapat dijalankan |

## Kapabilitas dan requirement bisnis

| ID | Kapabilitas | Hasil yang dibutuhkan |
|---|---|---|
| BR-AUTH-001 | Identitas | Login menerbitkan sesi untuk permukaan akun yang tepat dan revoke efektif segera |
| BR-CUS-001 | Akun dan alamat | Pesanan lama mempertahankan alamat snapshot |
| BR-GEO-001 | Data wilayah dan referensi | Alamat tervalidasi terhadap hierarki wilayah aktif |
| BR-CAT-001 | Katalog, varian dan media | Web hanya menampilkan produk terbit dan menyatakan cara beli per penawaran |
| BR-INV-001 | Gudang, stok dan ledger | Saldo konsisten dengan movement dan basis stok fisik |
| BR-RTS-001 | Siap kirim | Dua checkout tidak bisa memperoleh unit fisik yang sama |
| BR-PRE-001 | Preorder | Kuota tidak mengurangi stok RTS dan kegagalan dapat diproses untuk refund |
| BR-PRC-001 | Pengadaan | Unit yang sudah dibayar dan diterima dapat lanjut tanpa menunggu anggota batch lain |
| BR-REQ-001 | Request order | Satu request melahirkan paling banyak satu order |
| BR-CRT-001 | Keranjang | Mengubah cart tidak mengubah inventory atau kuota |
| BR-CHK-001 | Checkout | Retry intent yang sama memberi hasil sama; kegagalan satu grup membatalkan semuanya |
| BR-ORD-001 | Order dan penyelesaian | Histori dan snapshot tetap dapat diaudit |
| BR-IVC-001 | Invoice | Nominal invoice issued tidak dapat diubah |
| BR-PAY-001 | Transfer manual | Alokasi dana dan perubahan order terjadi atomik |
| BR-SHP-001 | Pengiriman dan tracking | Kuantitas kirim tidak melebihi unit layak, histori dapat ditelusuri |
| BR-RFD-001 | Refund | Konfirmasi transfer satu kali dengan audit dan rekonsiliasi |
| BR-RTN-001 | Return | Qty yang disengketakan tidak melebihi qty delivered dan resolusi terdokumentasi |
| BR-PRM-001 | Promo dan voucher | Kuota final tidak terlampaui secara concurrent |
| BR-CNT-001 | Konten toko | Publik hanya melihat versi terbit yang efektif |
| BR-NTF-001 | Notifikasi | Notifikasi in-app tidak duplikat dan email dilacak per delivery |
| BR-SRC-001 | Search dan SEO | Draft tidak ditemukan dan canonical/metadata tersedia |
| BR-RPT-001 | Dashboard dan laporan | Pada dashboard operasional, penerimaan bersih adalah pembayaran terverifikasi dikurangi refund selesai. Laporan keuangan Phase 2 memisahkan penerimaan, piutang dan laba berdasarkan pendapatan yang diakui, HPP dan biaya operasional. |
| BR-ADM-001 | Administrasi | Perubahan sensitif memiliki actor dan audit record |
| BR-FND-001 | Platform dan operasi | Build dan deployment dapat diulang dan kegagalan terlihat |
| BR-SEC-001 | Kontrol keamanan dan aksesibilitas | Operasi sensitif terlindungi dan keyboard journeys dapat diselesaikan |
| BR-EXT-001 | Ekstensi operasional | Ekstensi mempertahankan kontrak core dan ledger |

## Aturan bisnis dan operasi

- **Ready to ship.** Ketersediaan berasal dari gudang. Cart hanya estimasi. Pada checkout, pelanggan melihat harga dan ongkir domestik, lalu mendapat waktu terbatas untuk bayar dan mengunggah bukti. Dana yang diterima mengikat unit untuk packing dan shipping.
- **Preorder.** Penawaran terikat kampanye dan periode. Kuota, jumlah min/max, deposit atau penuh, dan ETA terpampang sebelum pembelian. Admin dapat memperpanjang tenggat, memperbarui ETA, mencatat partial receipt, dan menangani kekurangan supplier. Ongkir domestik dan biaya impor yang disetujui dapat ditagihkan sebelum kirim.
- **Request order.** Pelanggan mengisi link atau gambar, variasi, qty dan catatan. Staff menentukan kelayakan, membahas revisi, lalu mengirim quotation berjangka. Persetujuan konkret membuat invoice awal dan order untuk pengadaan.
- **Pembayaran dan invoice.** Harga item, pengiriman, diskon, pajak dan biaya lainnya diperlihatkan dengan dokumen tagihan. Finance mencocokkan bukti dengan mutasi rekening. Dokumen issued memiliki riwayat revisi dan akses pembeli yang berhak.
- **Pengiriman.** Alamat order disalin saat order dibuat. Paket dilacak dengan resi kurir. Pembeli melihat riwayat pengadaan terpisah dari riwayat kurir. Partial shipment diizinkan untuk barang yang diterima dan lunas.
- **Refund.** Finance menilai alasan dan komponen biaya yang dapat dikembalikan, termasuk selisih dan biaya pihak ketiga sesuai kebijakan yang disetujui. Setelah transfer, finance mencatat bukti dan nominal aktual.
- **Return.** Pembeli menyertakan foto barang/label dan video bila ada. Barang dikirim balik setelah instruksi. Gudang inspeksi dan CS memilih refund, replacement, repair atau pengiriman unit yang kurang.
- **Operasi.** Role menentukan antrean kerja, permission menentukan aksi. Kejadian kritis menghasilkan riwayat dan, bila relevan, email. Harga dan kebijakan baru berlaku prospektif.

## Sukses, dependensi, kendala dan risiko

| Ukuran | Cara ukur | Target awal (ASSUMPTION) |
|---|---|---|
| Oversell | Pesanan memperoleh unit/kuota melebihi saldo | 0 |
| Penggandaan dana | Settlement atau refund tercatat ganda | 0 |
| Kecepatan verifikasi | Dari bukti valid hingga keputusan pada hari kerja | P90 ≤ 1 hari kerja |
| Kelengkapan tracking | Paket dikirim dengan resi dan carrier tercatat | ≥ 98% |
| Kejelasan harga | Pesanan dengan snapshot dan faktur dapat ditelusuri | 100% |
| Tiket after-sales | Pengajuan dengan keputusan dan histori alasan | 100% |

Dependensi operasi mencakup rekening usaha, kebijakan publik, operator verifikasi, data wilayah, SMTP, akses gudang dan kurir. Risiko prioritas meliputi keterlambatan supplier dan biaya impor, penipuan bukti bayar, kelebihan penjualan, akses dokumen tanpa hak, dan mangkir pengembalian dana. PIC bisnis menetapkan kebijakan biaya dan keputusan komersial. Finance merekonsiliasi mutasi, gudang mencocokkan stok fisik, dan operator memantau antrean serta restore.

## Asumsi dan pertanyaan terbuka

| Kelompok | ID | Asumsi yang dipakai untuk desain | Keputusan yang dibutuhkan |
|---|---|---|---|
| Critical | Q-01 | ASSUMPTION satu badan usaha dan IDR, checkout lunas sebelum RTS dikirim | Setujui rekening, kewajiban pajak dan definisi invoice usaha |
| Critical | Q-02 | ASSUMPTION reserve 60 menit, bukti valid memperpanjang sampai maksimum 24 jam dari submit | Setujui SLA finance dan perlakuan transfer kedaluwarsa, termasuk late-payment review window 7 hari yang diusulkan |
| Critical | Q-03 | ASSUMPTION biaya impor/EMS disetujui pelanggan, dihitung per item, dan ditagih sebelum shipment | Tentukan rumus dan pemilik persetujuan biaya, aturan DP/minimum dan kurs |
| Critical | Q-04 | ASSUMPTION return 48 jam dari delivered, pembatalan setelah dana masuk melalui review | Tetapkan kondisi full/partial refund, potongan pihak ketiga dan pengecualian konsumen |
| Important | Q-05 | ASSUMPTION satu gudang RTS aktif sebagai default fulfillment untuk MVP; stok multi-gudang tetap tercatat | Tentukan gudang asal, SLA dispatch, dan tarif domestik awal |
| Important | Q-06 | ASSUMPTION multi-kurir manual; Biteship hanya kandidat integrasi tracking jika akses dan biaya disetujui | Pilih provider dan cek cakupan layanan |
| Important | Q-07 | ASSUMPTION staff punya email terpisah dan pengaitan Google ke akun lama memerlukan bukti akses akun lama | Setujui prosedur recovery dan onboarding staff |
| Important | Q-08 | ASSUMPTION Dokploy atau host Docker kompatibel, backup ke lokasi terpisah | Tentukan host, alamat email, incident owner dan target retensi |
| Can Be Decided Later | Q-09 | ASSUMPTION Shopee, gateway, promo, guest cart dan transfer gudang direncanakan Phase 2 | Prioritaskan provider dan urutan peluncuran |
| Can Be Decided Later | Q-10 | ASSUMPTION WhatsApp/push, mesin search eksternal, store credit, dan stock opname masuk Future | Mulai setelah metrik volume atau kebutuhan operasional disetujui |

Di luar rilis awal termasuk jalur di Q-09 dan Q-10, aplikasi mobile, marketplace multi-merchant, dan settlement multi-mata uang.

## Glosarium

| Istilah | Definisi |
|---|---|
| Available | Stok fisik dikurangi reservasi aktif dan unit yang committed |
| Preorder allocation | Unit kuota kampanye yang ditempati sementara atau telah terkonfirmasi |
| Group order | Pembelian preorder dalam satu kampanye, dengan pengadaan dapat digabung dalam batch |
| Quotation | Penawaran berjangka dan berversi untuk request tertentu |
| DP dan balance | Tagihan komitmen awal dan sisa harga yang wajib dibayar |
| TAX/EMS | Biaya impor dan pengiriman internasional yang dapat menjadi tagihan tambahan |
| Capture | Dana yang diterima dan diverifikasi, direpresentasikan di catatan payment |
| Fulfillment | Proses menyiapkan dan menyerahkan unit kepada kurir, terpisah dari status dana |
