# Task dan progress tracker

Sumber status pengerjaan development. Setiap epic di bawah memiliki feature PRD, task yang dapat diuji dan subtasks praktis di deskripsi. Milestone E00 menutup review dokumentasi dengan product owner. Bootstrap platform sudah digabung dan diverifikasi; fondasi async siap direview.

| Epic | Milestone | Dependensi milestone |
|---|---|---|
| E00 | Discovery dan arsitektur | — |
| E01 | Foundation | E00 |
| E02 | Auth dan RBAC | E01 |
| E03 | Master data dan katalog | E02 |
| E04 | Inventory | E03 |
| E05 | Cart dan checkout | E04 |
| E06 | Ready to ship | E05 |
| E07 | Preorder dan pengadaan | E06 |
| E08 | Request order | E07 |
| E09 | Manual payment dan invoice | E08 |
| E10 | Shipping dan tracking | E09 |
| E11 | Refund dan return | E10 |
| E12 | Admin dashboard | E11 |
| E13 | Storefront | E12 |
| E14 | Notifikasi | E13 |
| E15 | Testing dan hardening | E14 |
| E16 | Production readiness | E15 |
| E17 | Phase 2 commerce extensions | E16 |

Dependensi task mengatur jalur kritis. E05–E08 menggunakan kontrak dan test doubles untuk invoice/payment dan quote; integrasi final dengan implementasi di E09–E10. Build E05–E08 belum dianggap selesai sebagai journey produksi sebelum dependency integrasi dan test E15 lulus.

| ID | Epic | Module / feature | Task | Description / subtask | Dependency | Priority | Status | Progress | Owner / Agent | Acceptance criteria | Test requirement | Documentation requirement |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| TASK-000 | E00 | FND / FR-FND-001 | Review baseline | Konfirmasi ADR dan keputusan Q-01–Q-05 yang mempengaruhi build, rekam jawaban bisnis | — | P0 | NOT_STARTED | 0% | PO/Architect | Master prompt dan acceptance direview, scope dan dependency prioritas disetujui | TC-FND-001 | README, BRD, PRD, ADR |
| TASK-001 | E01 | FND / FR-FND-001 | Bootstrap platform | Siapkan struktur monorepo, Laravel/Nuxt, Compose, Make, env dan health; cek constraint paket | TASK-000 | P0 | DONE | 100% | Devin | API, DB, Redis, MinIO dan kedua web service sehat dari instruksi setup | TC-FND-001 | Architecture, Deployment |
| TASK-002 | E01 | FND / FR-FND-001 | Foundation async | Buat audit/history, idempotency, outbox, event consumption, Horizon dan scheduler | TASK-001 | P0 | READY_FOR_TEST | 75% | Devin | Commit/retry menyimpan 1 hasil domain dan event durable; failed jobs terlihat | TC-FND-001, TC-NTF-001 (queue; SMTP di TASK-035) | Schema, Architecture, API |
| TASK-003 | E01 | SEC / FR-SEC-001 | Storage dan policy | Provision bucket dan IAM, file policy, quarantine, scan, signed URL | TASK-002 | P0 | READY_FOR_TEST | 75% | Devin | Anonymous object private ditolak dan proof READY saja dapat dipakai | TC-SEC-001 | Security, Deployment, API |
| TASK-004 | E01 | FND / FR-FND-001 | Kontrak dan CI | Setup lint/static, PG integration, generate OpenAPI dan TS client, drift gate | TASK-002 | P0 | READY_FOR_TEST | 75% | Devin | Kedua frontend menerima tipe dari kontrak dan CI gagal pada perubahan drift | TC-FND-001 | Architecture, Testing, API |
| TASK-005 | E02 | AUTH / FR-AUTH-001 | Customer identity dan session | Daftar, verifikasi, login, reset dan revoke, seed mail fixture | TASK-002, TASK-004 | P0 | READY_FOR_TEST | 75% | Devin | Auth cookie/CSRF benar, token challenge single-use dan sesi terpisah | TC-AUTH-001 | PRD, API, Security |
| TASK-006 | E02 | AUTH / FR-AUTH-001 | Google dan akun lama | Socialite, verified email, link challenge, password proof dan rotasi | TASK-005 | P0 | READY_FOR_TEST | 75% | Devin | Collision email tidak mengambil akun tanpa akses owner | TC-AUTH-002 | API, Security |
| TASK-007 | E02 | ADM / FR-ADM-001 | Staff MFA dan RBAC | Undangan, TOTP, recovery, roles/policies dan guard per-action | TASK-005 | P0 | NOT_STARTED | 0% | Backend/Security | Role tidak hard-code dan last-admin guard diuji | TC-AUTH-002, TC-ADM-001 | Admin, API, Security |
| TASK-008 | E03 | GEO / FR-GEO-001 | Data referensi | Import wilayah, kurir master nanti, reason, unit dan kelas pajak | TASK-003, TASK-007 | P1 | NOT_STARTED | 0% | Backend | Hierarchy tervalidasi dan import kedua aman | TC-GEO-001 | Schema, API, Admin |
| TASK-009 | E03 | CUS / FR-CUS-001 | Profil dan alamat | CRUD customer, wishlist, default dan snapshot contract | TASK-008 | P1 | NOT_STARTED | 0% | Backend | Alamat lain 404, perubahan hanya berdampak ke order baru | TC-CUS-001 | Schema, API |
| TASK-010 | E03 | CAT / FR-CAT-001 | Produk dan SKU | Relasi produk, atribut, nilai, harga, publish dan media | TASK-008, TASK-003 | P0 | NOT_STARTED | 0% | Backend | Option dan SKU unique, DTO terbit konsisten | TC-CAT-001 | Schema, API, Admin |
| TASK-011 | E04 | INV / FR-INV-001 | Saldo dan movement | Warehouse, stock levels, receipt, adjustment dan low stock | TASK-010 | P0 | NOT_STARTED | 0% | Backend | Saldo hasil ledger, decrease tidak melebihi available | TC-INV-001 | Schema, Flows, Admin |
| TASK-012 | E04 | INV / FR-INV-001 | Reserve, commit, release, ship | Kontrak owner stok dan expiry lease, lock ordering, movements | TASK-011 | P0 | NOT_STARTED | 0% | Backend | Tidak ada reserve ganda dan timeout tidak lepas committed | TC-RTS-001, TC-RTS-002 | ADR, Schema, Flows |
| TASK-013 | E05 | CRT / FR-CRT-001 | Keranjang autentik | Version, upsert qty, offer provider port dan UX price change | TASK-009, TASK-010 | P0 | NOT_STARTED | 0% | Backend | Cart hanya intent; stale update 409 | TC-CRT-001 | Schema, API |
| TASK-014 | E05 | CHK / FR-CHK-001 | Quote dan checkout orchestration | Kontrak shipping-rate, quote hash, multi-order split dan tx boundary | TASK-012, TASK-013 | P0 | NOT_STARTED | 0% | Backend | Satu transaksi dan intent per cart version, rollback jika salah satu grup gagal | TC-CHK-001, TC-CHK-002 | API, Schema, Flows |
| TASK-015 | E05 | ORD / FR-ORD-001 | Order core | Nomor, snapshot, state, owner reads, cancel/completion contract | TASK-009, TASK-012 | P0 | NOT_STARTED | 0% | Backend | Histori order tidak berubah oleh perubahan master; transisi dijaga | TC-ORD-001 | Schema, Flows, API |
| TASK-016 | E06 | RTS / FR-RTS-001 | Ready stock checkout | Hook order core, inventory port, shipping quote dan initial invoice port | TASK-014, TASK-015 | P0 | NOT_STARTED | 0% | Backend | Last-unit contention hanya menang satu; state menunggu payment | TC-RTS-001, TC-CHK-002 | Flows, API |
| TASK-017 | E07 | PRE / FR-PRE-001 | Kampanye, varian dan quota | Window, harga, termin, ETA, reserve/confirm dan release kuota | TASK-016 | P0 | NOT_STARTED | 0% | Backend | Kuota terbatas dan terpisah dari saldo fisik | TC-PRE-001, TC-PRE-002 | Schema, Admin, Flows |
| TASK-018 | E07 | PRC / FR-PRC-001 | Batch dan supplier shortage | Batch ownership, stage history, receipt, failure dan unit eligibility | TASK-017 | P0 | NOT_STARTED | 0% | Backend | Partial receipt boleh lanjut per buyer tanpa allocation ganda | TC-PRC-001, TC-PRE-002 | Schema, Flows, API |
| TASK-019 | E08 | REQ / FR-REQ-001 | Request dan thread | Form, items, private attachments, case review dan staff/customer visibility | TASK-018, TASK-003 | P0 | NOT_STARTED | 0% | Backend | Lampiran scoped dan notes internal tidak bocor | TC-REQ-001, TC-SEC-001 | Schema, API, Admin |
| TASK-020 | E08 | REQ / FR-REQ-001 | Quotation dan konversi | Versi, freeze, expiry, keputusan customer, transaksi order/invoice | TASK-019, TASK-015 | P0 | NOT_STARTED | 0% | Backend | Accept dua kali hanya satu conversion, expired ditolak | TC-REQ-001 | Flows, API, Schema |
| TASK-021 | E09 | IVC / FR-IVC-001 | Invoice dan PDF | Implement issue port, purpose dan snapshot, void/revision, render async | TASK-020, TASK-003 | P0 | NOT_STARTED | 0% | Backend | Satu invoice per source, PDF private, snapshot issued immutable | TC-IVC-001 | Schema, API, Flows |
| TASK-022 | E09 | PAY / FR-PAY-001 | Intent, proof dan finance review | Bank instructions, alokasi intent, proof, hold extension, review/reject | TASK-021, TASK-007 | P0 | NOT_STARTED | 0% | Backend | Hanya proof READY dan owner; finance review lease dan audit | TC-PAY-001, TC-PAY-002 | API, Security, Admin |
| TASK-023 | E09 | PAY / FR-PAY-001 | Settlement dan recovery | Apply payment, commit RTS/PO, recovery receipt, second actor dan return proof | TASK-022, TASK-012, TASK-017 | P0 | NOT_STARTED | 0% | Backend | Capture sekali, late/mismatch masuk rekonsiliasi dan tidak oversell | TC-PAY-001, TC-PAY-002, TC-RTS-002 | Flows, Schema, Admin |
| TASK-024 | E09 | IVC / FR-IVC-001 | Termin dan charge consent | Balance eligible, consent quote domestik dan fee impor, revisi shortage | TASK-023, TASK-018 | P0 | NOT_STARTED | 0% | Backend | Only accepted fee invoiced dan semua porsi paid sebelum dispatch | TC-PRE-002, TC-IVC-001 | API, Flows, Schema |
| TASK-025 | E10 | SHP / FR-SHP-001 | Multi-carrier dan paket | Master kurir/rates, manual quote, create package, eligibility dan dispatch | TASK-024, TASK-016 | P0 | NOT_STARTED | 0% | Backend | Ready stock mengurangi on_hand di dispatch, PO/RO tidak memakai ledger RTS | TC-SHP-001, TC-INV-001 | ADR, API, Flows, Admin |
| TASK-026 | E10 | SHP / FR-SHP-001 | Tracking dan completion | Manual timeline, failed recovery, port normalisasi dan polling fake | TASK-025 | P0 | NOT_STARTED | 0% | Backend | Duplicate dan event lama tidak regresi, completion sesudah syarat terpenuhi | TC-SHP-001, TC-SHP-002, TC-ORD-001 | Flows, API |
| TASK-027 | E11 | RFD / FR-RFD-001 | Refund review dan transfer | Cap and quota, maker/checker, proof, record transfer dan reconciliation | TASK-026, TASK-023 | P0 | NOT_STARTED | 0% | Backend | Replay/duplikat tidak melebihi capture atau mengirim ulang transfer | TC-RFD-001, TC-SEC-001 | Flows, API, Admin |
| TASK-028 | E11 | RTN / FR-RTN-001 | Return dan resolusi | Window per shipment, proof, instruksi, inspection, restock/replacement/refund | TASK-027 | P0 | NOT_STARTED | 0% | Backend | Claim qty cap dan restock sekali; resolution punya outcome | TC-RTN-001 | Flows, Schema, Admin |
| TASK-029 | E12 | RPT / FR-RPT-001 | Dashboard dan finance reports | Agregasi, filter, queue status, low stock, transaksi dan no raw PII | TASK-028, TASK-007 | P1 | NOT_STARTED | 0% | Backend/Admin | Capture-refund benar, setiap report scope sesuai grant | TC-RPT-001, TC-ADM-001 | PRD, API, Admin |
| TASK-030 | E12 | ADM / FR-ADM-001 | Shell dashboard dan antrean | MFA login, sidebar perm, CRUD dan seluruh action operasional MVP | TASK-029, TASK-004 | P0 | NOT_STARTED | 0% | Admin Frontend | Order, payment, shipment, refund, return, procurement, audit, settings dapat dikelola | TC-ADM-001, TC-PRE-002, TC-RFD-001, TC-RTN-001 | Admin, Testing |
| TASK-031 | E12 | CNT / FR-CNT-001 | Content editor | Homepage, banner, FAQ, kebijakan, publish dan sanitizer | TASK-030, TASK-010 | P1 | NOT_STARTED | 0% | Backend/Admin | Draf privat, media valid, HTML aman | TC-CNT-001 | API, Security, Admin |
| TASK-032 | E13 | SRC / FR-SRC-001 | Search dan SEO | PostgreSQL query/filter, storefront SSR, canonical, sitemap, metadata | TASK-031, TASK-010 | P1 | NOT_STARTED | 0% | Backend/Storefront | Draft tak terlihat, search memenuhi target dengan fixture | TC-SRC-001 | Architecture, PRD, API |
| TASK-033 | E13 | CUS / FR-CUS-001 | Storefront account dan discovery | Auth, profil, alamat, wishlist, listing, detail, konten dan navigation | TASK-032, TASK-006, TASK-009 | P1 | NOT_STARTED | 0% | Storefront | Pembeli dapat login, kelola alamat, pahami tiga cara beli dan pakai keyboard | TC-CUS-001, TC-AUTH-002, TC-SEC-002 | PRD, Testing |
| TASK-034 | E13 | CHK / FR-CHK-001 | Storefront purchase dan after-sales | Cart, preorder, checkout, request/quotation, transfer, invoice, shipment/refund/return UI | TASK-033, TASK-028 | P0 | NOT_STARTED | 0% | Storefront | Jalur RTS/PO/RO berfungsi di UI dan status/stale/empty/error terwakili | TC-CHK-002, TC-PRE-002, TC-REQ-001, TC-SHP-001, TC-RTN-001, TC-SEC-002 | PRD, Testing |
| TASK-035 | E14 | NTF / FR-NTF-001 | Email dan inbox | Template auth/order/payment/PO/request/shipping/refund/return, Mailpit, dedup dan read receipt | TASK-034, TASK-002 | P1 | NOT_STARTED | 0% | Backend/Storefront | Delivery terukur, retry tidak menggandakan inbox, status email terbaca | TC-NTF-001, TC-AUTH-001 | Architecture, API, Deployment |
| TASK-036 | E15 | SEC / FR-SEC-001 | Security dan aksesibilitas | Review izin route, upload, PII log, OAuth/re-auth, keyboard dan component states | TASK-035, TASK-030 | P0 | NOT_STARTED | 0% | Security/QA | Checklists release, WCAG journey dan storage suite lulus | TC-ADM-001, TC-SEC-001, TC-SEC-002, TC-AUTH-002 | Security, Testing |
| TASK-037 | E15 | CHK / FR-CHK-001 | Integrasi dan load suite | E2E lifecycle, concurrency 100 checkout, queue/provider fakes dan baseline performance | TASK-036 | P0 | NOT_STARTED | 0% | QA | Integrity tests lulus berulang dan metrik tercatat | TC-RTS-001, TC-RTS-002, TC-PRE-001, TC-REQ-001, TC-PAY-001, TC-CHK-001, TC-CHK-002, TC-SHP-002, TC-RFD-001, TC-NTF-001, TC-SRC-001 | Testing, Flows |
| TASK-038 | E16 | FND / FR-FND-001 | Staging, backup dan restore | Pipeline image, runbook, alert, migration/rollback drills dan smoke | TASK-037 | P0 | NOT_STARTED | 0% | Platform | RPO/RTO terukur, health dan rekonsiliasi sesudah restore | TC-FND-001 | Deployment, Security |
| TASK-039 | E16 | FND / FR-FND-001 | Go-live review | Uji operasi dengan PIC finance/gudang, konfirmasi kebijakan dan rollout | TASK-038 | P0 | NOT_STARTED | 0% | PO/Platform | Tidak ada critical blocker dan signoff produksi terarsip | TC-FND-001, TC-ORD-001 | README, Deployment |
| TASK-040 | E17 | PRM / FR-PRM-001 | Promosi dan coupon | Eligibility, deterministic rounding, cap global/per user, portal dan hold expiry | TASK-039 | P2 | NOT_STARTED | 0% | Commerce | Kuota concurrency aman, discount visible dan audited | TC-PRM-001 | PRD, API, Schema |
| TASK-041 | E17 | EXT / FR-EXT-001 | Payment gateway | Sandbox webhook, adapter, settlement dan refund port | TASK-039 | P2 | NOT_STARTED | 0% | Payments | Inbox dan capture dedup, manual path tetap tersedia | TC-EXT-001, TC-PAY-001 | ADR, API, Security |
| TASK-042 | E17 | EXT / FR-EXT-001 | Shipping provider dan Shopee | Rate/tracking sandbox, polling/429 dan marketplace delivery confirmation | TASK-039 | P2 | NOT_STARTED | 0% | Shipping | Provider failure fallback manual, status normalized dan dedup | TC-SHP-002, TC-EXT-001 | ADR, API, Flows |
| TASK-043 | E17 | EXT / FR-EXT-001 | Guest cart, stock transfer dan laporan | Cart merge versioned, paired movements, agregasi lanjutan | TASK-039 | P2 | NOT_STARTED | 0% | Commerce | Deterministic cart merge, stock invariant dan report access | TC-EXT-001, TC-INV-001, TC-RPT-001 | PRD, Schema, Admin |

## Status, kemajuan, dan Definition of Done

Status yang diizinkan: NOT_STARTED, IN_PROGRESS, BLOCKED, READY_FOR_TEST, TESTING, FAILED, DONE. Kemajuan hanya 0%, 25%, 50%, 75%, 100%. 0% belum mulai, 25% desain/branch aktif, 50% jalur inti terhubung, 75% siap verifikasi dan 100% setelah seluruh DoD. BLOCKED mencatat dependency dan pemilik keputusan. FAILED mencatat test atau acceptance yang gagal. Owner/Agent di sini merupakan peran; saat pickup ganti dengan PIC yang nyata.

- Backend selesai bila migration/model/relationship, constraint, validation/authorization, action/API, OpenAPI, unit/feature tests, edge case, race analysis, audit dan docs terkait tuntas.
- Frontend selesai bila responsif, loading/empty/error dan validation, API/permission/feedback, aksesibilitas, tests dan docs tuntas.
- DONE / 100% mensyaratkan implementasi, migration yang relevan, test ditulis dan lulus, dokumen diperbarui, review selesai, dan seluruh known critical issues ditutup. Perubahan requirement memperbarui [traceability](REQUIREMENTS-TRACEABILITY.md).
