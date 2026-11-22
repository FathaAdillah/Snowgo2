# Final consistency review

Status rancangan: siap ditinjau product owner. [Pertanyaan dan asumsi](01-BRD.md#asumsi-dan-pertanyaan-terbuka) menentukan konfigurasi bisnis awal. Semua task development berada di NOT_STARTED / 0%.

## Pemeriksaan silang

| Pemeriksaan | Hasil review |
|---|---|
| BRD × PRD | 26 BR memiliki FR. Transfer manual dan tracking manual masuk MVP, penyedia eksternal serta Shopee masuk Phase 2. Batas operasional dan asumsi tetap terlihat di BRD. |
| PRD × database | Setiap feature dan NFR ditelusuri ke model, tabel infrastruktur atau kontrak modul pemilik. Storefront SSR, UI dan proyeksi laporan tidak memerlukan tabel transaksi baru. |
| PRD × API | Lima permukaan API mencakup lifecycle MVP dan aksi staff. Integrasi Phase 2 yang tersedia di katalog diberi fase, rincian vendor dan fitur Phase 2 lain menjadi acceptance backlog. |
| API × database | ID bisnis/ULID, enum, nominal whole IDR, file READY dan owner, unique source, idempotency serta referensi invoice/payment selaras dalam rancangan. |
| Flow × state | State guards per aggregate, packing paket terpisah dari order, histori refund tidak mengubah invoice PAID, dan late transfer hanya lewat rekonsiliasi. |
| Admin × API | 183 endpoint admin berizin memiliki referensi menu. Staff credential flow menggunakan permukaan auth admin. |
| Admin × RBAC | 88 permission API admin dipetakan ke grant; kondisi maker-checker, step-up, dan ownership dicatat. |
| Tracker × PRD | Setiap feature memiliki task dan test di traceability. E00 mendahului development dan E17 mengambil scope Phase 2. |
| Tracker × database dan API | Model dan endpoint tersedia melalui FR di traceability; urutan task bebas siklus. Dependency terhadap komponen yang muncul belakangan memakai kontrak/fake hingga integrasi. |
| Testing × jalur kritis | 33 skenario mencakup race PostgreSQL, storage, mail, queue, provider, E2E, security, dan restore. |

## Bukti cakupan lifecycle

| Lifecycle | Kontrak dan risiko utama | Test utama |
|---|---|---|
| Ready to ship / inventory | Cart version, hold, commit, dispatch dan release TTL; saldo tidak negatif | TC-RTS-001, TC-RTS-002, TC-INV-001, TC-CHK-001 |
| Preorder | Kuota per campaign, DP/pelunasan, partial receipt dan shortage/refund | TC-PRE-001, TC-PRE-002, TC-PRC-001 |
| Request order | Review, revisi quotation, expiry dan satu konversi atomik | TC-REQ-001 |
| Manual payment | Proof privat, approval atomik, transfer exception dan bank ref dedup | TC-PAY-001, TC-PAY-002 |
| Invoice | Source unique, issue immutable, balance/consent, PDF privat | TC-IVC-001, TC-PRE-002 |
| Shipping/tracking | Resi manual, partial dispatch, duplicate events dan provider fallback | TC-SHP-001, TC-SHP-002 |
| Refund | Review, cap, maker-checker, evidence dan settlement ulang yang aman | TC-RFD-001 |
| Return | Delivery window, claim qty, receipt, inspeksi dan resolusi | TC-RTN-001 |

## Ringkasan keputusan dan ukuran rancangan

| Kategori | Hasil |
|---|---|
| Architecture decision | API-first Laravel modular monolith, dua aplikasi Nuxt, PostgreSQL sebagai sumber transaksi |
| Tech stack | Laravel 13/PHP 8.4, Nuxt 4/Vue 3, Nuxt UI admin, Tailwind storefront, PostgreSQL 16, Redis 7, Horizon, MinIO, Mailpit, Nginx, Compose |
| Modul / tabel | 23 modul backend, 90 tabel rancangan termasuk platform dan ekstensi yang ditandai |
| API | 5 kelompok, 264 operasi method+path |
| Requirement / test | 26 feature requirements, 7 NFR, 33 skenario test |
| Backlog | 18 epic, 44 task |
| Risiko kritis | Oversell, double settlement/refund, transfer terlambat, privilege dan bukti bocor, supplier gagal serta perubahan biaya impor |
| Open questions | 4 Critical, 4 Important, 2 Can Be Decided Later |
| MVP | Ketiga cara beli, auth/RBAC, catalog, inventory, cart/checkout, transfer manual, invoice, kurir dan resi manual, refund/return, admin, storefront, email dan audit |
| Phase 2 | Gateway, aggregator tracking, Shopee, promo/kupon, guest cart, stock transfer dan reporting lanjutan |
| Future | Search eksternal, mobile, stock opname, WhatsApp/push/SMS dan store credit |

Pemeriksaan dokumen meliputi tautan Markdown lokal, ID referensi dan rentang, pasangan method/path, coverage permission/menu, FK target, dependency task dan parsing 22 blok Mermaid. Runtime aplikasi, migration dan skenario test bisnis baru diverifikasi saat implementasi. Pilihan package yang perlu dibuktikan dirangkum dalam spike Foundation. Akurasi SLA, tarif, pajak/biaya impor, dan refund mengikuti review bisnis Q-01–Q-05.

## Documentation status

- [x] Reference Architecture Analysis dan Requirement Gap Analysis
- [x] BRD dan PRD
- [x] Tech Stack, Architecture dan 5 ADR
- [x] Database Schema dan ERD
- [x] API Contract dan kamus tipe
- [x] Business Flows dan State Machines
- [x] Admin Menu dan RBAC
- [x] Security dan Testing Strategy
- [x] Deployment dan recovery
- [x] Task & Progress Tracker dan Requirements Traceability
- [x] Final Consistency Review
- [ ] Product owner approval

[Indeks dokumentasi](README.md)
