# Analisis gap requirement

Sumber teknis adalah [observasi CrowdFunding](REFERENCE-ARCHITECTURE-ANALYSIS.md). Masukan bisnis berasal dari prompt dan halaman publik Snowe GO. Situs memperlihatkan DP dan pelunasan, batch PO, biaya TAX/EMS, kurir atau Shopee, dan pembayaran gateway. Cakupan rilis mengikuti BRD dan PRD di bawah ini.

| Area | Fondasi yang tersedia di referensi | Gap Snowego | Keputusan rancangan |
|---|---|---|---|
| Identitas | Sanctum, Fortify, Spatie | Dua permukaan akun, Google, revoke per perangkat | Cookie host-only, sesi DB, MFA staff, permission dan policy |
| Catalog dan search | CRUD, media S3, portal SSR | Varian SKU, kategori, SEO, wishlist, search | Model katalog dan read model; pencarian PostgreSQL |
| Ready to ship | Row lock dan enum transisi | Ledger per gudang, reserve/commit/ship | Reservasi ber-TTL pada checkout, movement append-only |
| Preorder | Pola alokasi dari project referensi | Kuota kampanye terpisah, batch, sebagian terpenuhi, DP | Kuota per varian, procurement stages, invoice per termin |
| Request order | Approval sebagai pola arsitektur | Diskusi, versi penawaran, acceptance atomik | Aggregate independen dan satu konversi per request |
| Checkout dan finance | Transaksi PostgreSQL dan adapter pembayaran | Harga/alamat snapshot, invoice immutable, bukti transfer, alokasi | Kunci transaksi, rekonsiliasi manual, provider port |
| Shipping | Reverse proxy, job, adapter sebagai pola | Multi-kurir, resi, polling/webhook, tracking dan pengiriman sebagian | Manual fulfillment dan adapter opsional |
| After-sales | Audit dan verifikasi | Jendela return, inspection, pengembalian dana item-level | Refund/return terpisah, cap refund pada dana diterima |
| Dokumen sensitif | Driver S3 | Akses bukti privat di storage | Bucket dan IAM terpisah, policy sebelum URL sementara |
| Async | Worker/scheduler dan Redis | Mailpit, penerbitan andal, retry, queue monitoring | PostgreSQL outbox, Horizon, scheduler, SMTP |
| Delivery | Compose dan Makefile | Contract coverage, test PostgreSQL, prosedur rilis/restore | Satu image API, verifikasi kontrak, dry-run restore |
| Admin | RBAC catalog | Operasi lintas lifecycle, permission per tindakan | Menu berdasarkan permission, API memeriksa ulang scope |
| Storefront | Next.js storefront, Vue admin | Permintaan Vue di kedua aplikasi | Nuxt 4 pada keduanya, SSR storefront |

[Lanjut ke BRD](01-BRD.md) · [PRD](02-PRD.md) · [Arsitektur](03-TECH-STACK-ARCHITECTURE.md)
