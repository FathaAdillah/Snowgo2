# Testing strategy

Rencana berikut menjadi acceptance development. Gunakan data sintetis, ID fixture, clock deterministik dan PostgreSQL versi produksi. Satu test concurrency menjalankan koneksi/proses terpisah dengan barrier, transaction isolation READ COMMITTED dan timeout. Jangan menjalankannya di dalam transaksi test tunggal yang membuat fixture tidak terlihat. Setelah join, baca saldo, constraints, ledger, invoice/event count dan log retry dari database baru.

| Lapisan | Tool dan cakupan |
|---|---|
| Unit | Pest, PHP money, state transitions, campaign, rounding, provider status map dan diskon |
| Architecture | Pest arch untuk import lintas modul, controllers, migrations dan permission naming |
| Feature dan API | Pest/Laravel HTTP, PostgreSQL dan OpenAPI response validation; happy path, 401/403/404/409/422, page/cursor dan idempotency |
| Integration dan database | PostgreSQL, Redis, MinIO, Mailpit di CI; FK, constraint, partial index, sequence, JSONB, queue dan rollback |
| Concurrency | Barrier multi-process, repeat race minimal 10× untuk SKU terakhir, verify, refund dan quote; fixture isolated schema |
| Queue dan email | Horizon/Redis job atau worker asli pada integration, fakes untuk unit; Mailpit API di staging/CI, recipient/subject/template tanpa PII berlebih |
| Storage | MinIO private buckets, scan fakes dan smoke scanner sungguhan, URL expiry, re-upload |
| Provider | ShippingProvider fake fixture repeat/out-of-order/outage; sandbox adapter Phase 2 bila kredensial tersedia; tidak hit layanan produksi |
| Frontend component | Vitest, Vue Test Utils, route guards, async states, SSR hydration dan aksesibilitas |
| E2E | Playwright di staging/CI, customer dan staff simulasi MFA, transfer proof, tracking, refund dan return |
| Security | Akses lintas akun, CSRF/cookie, MFA/recovery, file access, content sanitizer, rate limit, permission dan secret rotation |
| Load | k6 atau setara, warm-up, ramp dan contention 100 checkout; katalog p95, DB lock wait, queue lag. Publish fixture size dan machine profile |

## Test cases dan acceptance

TC-CHK-001 menyiapkan 100 keranjang dan quote sebelum melepas worker melalui barrier. Suite standar memakai 10 worker agar muat di Compose default. Untuk 100 proses konfirmasi serentak, gunakan PostgreSQL **uji** dengan setidaknya 110 koneksi tersedia dan jalankan `CHECKOUT_STRESS_100=1` pada kontainer API test. Mode ini menguji transaksi PostgreSQL, bukan throughput HTTP.

| ID | Jenis | Skenario | Lulus bila |
|---|---|---|---|
| TC-AUTH-001 | Feature/API/security | Daftar, verifikasi, reset dan sesi customer; revoke satu device, periksa owner | Gagal sebelum verified; sesi revoked 401; respons reset seragam |
| TC-AUTH-002 | Integration/security | Google fake, state kedaluwarsa dan akun dengan email sama, login staff + MFA | Tidak ada takeover; link setelah bukti akses akun, role staff membutuhkan MFA |
| TC-CUS-001 | Feature/E2E | Ubah alamat default, wishlist, cek snapshot order lama | Satu default; snapshot tidak berubah dan alamat lintas akun 404 |
| TC-GEO-001 | Database/API | Import dua kali, parent salah tipe dan code bentrok | Hierarchy valid, import kedua idempotent, audit tersedia |
| TC-CAT-001 | Feature/component | Publish varian/atribut dan media valid, duplikat SKU | Publik terlihat sesuai penawaran, invalid 422/409 dan editor bisa pulih |
| TC-INV-001 | Database/concurrency | Adjustment dan dispatch bersamaan, audit dan receipt ulang | Movement menjumlah ke saldo; available tidak negatif |
| TC-RTS-001 | Concurrency | Dua customer membeli SKU terakhir dengan koneksi PostgreSQL terpisah dan start barrier | Tepat satu sukses dan satu 409; invariant saldo + satu reservation + satu order |
| TC-RTS-002 | Concurrency/feature | Expiry reservation versus approval/proof; ulang expiry job | Satu pemenang, tidak ada release committed, late payment ke reconciliation |
| TC-PRE-001 | Concurrency | Dua customer membeli kuota preorder terakhir | Satu sukses; quota invariant dan ledger RTS tidak berubah |
| TC-PRE-002 | Feature/E2E | DP, ETA, partial supplier failure, lunasi item eligible | DP benar, revisi invoice dan refund shortage, unit lunas dapat ship |
| TC-PRC-001 | Feature/database | Batch berisi beberapa buyer, partial receipt, transition invalid dan retry | Qty tidak ganda, setiap buyer eligible independen, status maju |
| TC-REQ-001 | Concurrency/E2E | Accept quotation yang sama dari dua request paralel, versi expired | Satu conversion dan invoice; replay konsisten, versi invalid 409 |
| TC-CRT-001 | Feature/component | Cart campuran term dan edit dari dua tab | Stale version konflik, tidak ada inventory writes |
| TC-CHK-001 | Concurrency/load | 100 customer authenticated independent, masing-masing qty 1, stok 12, barrier start | Tepat 12 sukses dan 88 conflict stok, order/invoice dan saldo konsisten |
| TC-CHK-002 | API/database | Checkout campuran, salah satu grup gagal; retry dengan key sama/berbeda | Rollback seluruhnya; replay identik; payload berubah 422 |
| TC-ORD-001 | Feature/E2E | Cancel sebelum capture, setelah capture, pack sampai completion | Hold lepas sekali, captured membutuhkan kasus refund, completion hanya bila eligible |
| TC-IVC-001 | PostgreSQL/queue/MinIO | Dua penerbit pengganti, void saat review/applied, revisi jatuh tempo, PDF retry dan replay job | Satu nomor per source; snapshot dan line immutable; URL sementara berfungsi hanya setelah siap, unduh tanpa signature ditolak |
| TC-PAY-001 | Concurrency | Approve payment dipanggil dua kali dengan key sama/berbeda | Satu capture dan komitmen, audit approval dan allocations tunggal |
| TC-PAY-002 | Feature/integration | Mismatch dan late exact transfer, dua order atau invoice berbeda dan bank ref sama | Tidak ada double capture; recovery berizin apply atomik atau return dengan proof |
| TC-SHP-001 | Feature/E2E | Partial shipment, resi, tracking manual FAILED lalu selesai | Dispatch qty dan SALE sekali; order delivered setelah semua paket selesai |
| TC-SHP-002 | Provider mock/concurrency | Webhook shipping duplikat, out-of-order, invalid, polling dan provider 429/outage | Satu event per ID, signature invalid ditolak, status tidak mundur dan backoff benar |
| TC-RFD-001 | Concurrency/API | Refund request duplicate, approve paralel dan transfer retry | Satu case per replay, cap tidak terlampaui, transfer reference tidak gandakan refund |
| TC-RTN-001 | Feature/E2E | Ajukan dalam/di luar 48 jam, inspeksi, RTS restock dan replacement | Qty cap lintas klaim; movement sekali; resolusi menunggu outcome |
| TC-PRM-001 | Concurrency | Last coupon digunakan concurrent lintas user dan per user; rollback checkout | Aggregate dan per-user cap, expired release, promo deterministik |
| TC-CNT-001 | Component/feature | Render page draft dan published, payload HTML tak diizinkan | Publik hanya melihat published yang efektif dan markup aman |
| TC-NTF-001 | Queue/email | Crash sesudah publish, duplikat job, SMTP delay dan ambiguous send | Inbox sekali, retry delivery sesuai kebijakan, Mailpit menerima template QA |
| TC-SRC-001 | Load/API/SEO | 10 ribu SKU terbit + draft, search dan filter pada 50 RPS, cek sitemap/canonical | Draft tidak bocor, p95 ≤ 400 ms dan markup valid |
| TC-RPT-001 | Feature/API | Dataset campur capture/refund/pending, role laporan | Agregasi tepat dan akses non-finance dibatasi |
| TC-RPT-002 | API/admin · direncanakan | Capture DP di akhir bulan WIB, refund selesai bulan berikutnya, bukti belum diverifikasi, invoice jatuh tempo dan invoice void/diganti | Grafik memakai tanggal transaksi masing-masing; total per jalur sama dengan total periode, piutang hanya menghitung sisa tagihan yang berlaku; izin dashboard umum tidak membuka data finance |
| TC-RPT-003 | API/admin · direncanakan | Penerimaan DP sebelum pengakuan, penjualan Rp100.000 dengan HPP Rp60.000 dan biaya Rp10.000; ulangi dengan HPP kosong, retur/refund dan role hanya-baca | DP tidak menjadi laba; laba kotor Rp40.000 dan laba operasional Rp30.000; HPP kosong tidak menghasilkan laba final; koreksi tidak ganda, perubahan biaya tanpa izin ditolak |
| TC-ADM-001 | Security/API | Setiap permission route smoke CRUD, revoke dan administrator terakhir | Route 403 tanpa permission; privilege escalation dan last-admin removal ditolak |
| TC-FND-001 | Integration/ops | Health failure, Redis/DB restart, outbox recovery, restore terisolasi | Readiness 503 saat gagal, backlog pulih, RPO/RTO tercatat |
| TC-SEC-001 | Storage/security | MIME mismatch, malware fixture aman EICAR, akses file cross-owner dan URL expired | Upload ditolak/karantina, private bucket menolak anonymous, signed link expire |
| TC-SEC-002 | Component/E2E accessibility | Keyboard checkout, dialog dan form validation; axe, fokus, contrast | Core flow keyboard bisa selesai, tidak ada pelanggaran AA kritis |
| TC-EXT-001 | Integration/contract | Gateway sandbox/fake, Shopee fallback, guest cart merge, transfer antar gudang | Dedup finance, jalur manual, cart merge deterministic dan stok konsisten |

Checkout load case memisahkan conflict bisnis dari error teknis. Threshold tambahan awal P95 checkout ≤ 1.500 ms di staging tanpa pemanggilan provider eksternal dalam lock (ASSUMPTION). Test outbox dan SMTP mengakui semantik at-least-once; gunakan status AMBIGUOUS dan rekonsiliasi bila provider tidak memberikan dedup key.

Untuk merge: Pint, Larastan, web lint/typecheck dan unit, feature/API/PostgreSQL integration, OpenAPI drift, dependency/image checks dan build container. Untuk promote staging: E2E, storage/email/provider mock, concurrency dan smoke migration/rollback. Untuk rilis: load baseline, review akses/permission dan restore exercise. Task hanya DONE setelah test requirement dan dokumen terkait lulus. Traceability menghubungkan tiap TC ke requirement dan task.

**TASK-015 / TC-ORD-001** · Jalankan `php artisan test --filter=OrderCoreTest` pada database PostgreSQL testing. Checkout nyata membentuk fixture RTS/PO; dua proses pembatalan PO hanya menghasilkan satu pelepasan. Guard completion diuji dengan proyeksi delivery, invoice dan dispute yang disiapkan sebagai fixture, bukan integrasi kurir atau bank. Akses staf diuji terpisah untuk lihat, lihat PII, cancel, complete, dan replay setelah izin dicabut.

**TASK-016 / TC-RTS-001, TC-CHK-002** · `php artisan test --filter=CheckoutTest` pada PostgreSQL testing. Dua pembeli, dua koneksi dan satu unit terakhir: satu order menunggu pembayaran, satu invoice, satu reservasi aktif; pihak lain mendapat `409 OUT_OF_STOCK` dan keranjangnya tetap aktif. Uji rollback juga mencakup kegagalan konversi cart sesudah konsumsi quote: pilihan kirim tetap bisa dipakai saat retry. Mengubah master kurir, layanan atau tarif tidak mengubah detail kirim historis.
