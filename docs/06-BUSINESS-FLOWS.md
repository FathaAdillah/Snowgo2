# Business flows dan state machines

Satu perintah bisnis = transaksi PostgreSQL, riwayat perubahan dan outbox. Komunikasi SMTP, PDF dan provider di luar transaksi. Aksi normal dan recovery memakai policy, alasan dan correlation ID. Checkout preview quote berumur 15 menit (ASSUMPTION), pricing hash mencakup semua offer, term, tax, shipping, alamat dan cart version. Kupon ditolak saat flag Phase 2 mati. Di bawah, nama status uppercase sama dengan DB dan API. [API](05-API-CONTRACT.md) · [Database](04-DATABASE-SCHEMA.md)

## Ready to ship

- **Aktor dan prasyarat** · Pembeli verified, SKU terbit, level tersedia di gudang default, alamat aktif, tarif RTS valid.
- **Langkah dan data** · Cart hanya menyimpan intent. Preview memilih tarif berdasarkan gudang dan berat, kembalikan quote/hash. Submit kunci cart version, cek snapshot harga, alamat, pilihan shipping, dan lock level terurut. Satu checkout membuat satu RTS order dan satu order per kampanye PO yang ikut dibeli, masing-masing invoice. Simpan snapshot alamat/item, reserve dan convert cart atomik.
- **Status dan event** · Order PENDING_PAYMENT dengan StockReservation ACTIVE. InvoiceIssued, OrderCreated, StockReserved memicu instruksi bayar. Capture mengubah reservation menjadi COMMITTED dan order CONFIRMED. Packing dan dispatch operator mengubah fulfillment; SALE memindahkan on_hand/committed hanya saat dispatch.
- **Gagal/pulih** · Perubahan harga/promo/tarif → QUOTE_CHANGED untuk review total. Unit kurang → OUT_OF_STOCK dan rollback semua grup. Retry key yang sama membaca hasil sebelumnya.

## Preorder dan pengadaan

- **Aktor dan prasyarat** · Pembeli dan order staff. Kampanye OPEN dalam window, qty akun memenuhi min/max, customer memilih FULL/DEPOSIT sesuai skema.
- **Langkah dan data** · Lock campaign variant dan cek total alokasi aktif akun saat checkout. Tulis preorder_allocations RESERVED dengan TTL pembayaran. Harga/termin disalin ke order dan invoice awal. Setelah dana initial verified, RESERVED → CONFIRMED. Staff tutup atau scheduler menutup, buat batch per kampanye, update tahapan seller → origin warehouse bila ada → transit → Indonesia → penilaian impor → received. Status kampanye dapat mencapai FULFILLING setelah receipt pertama. Kuota historis confirmed dipertahankan.
- **Tagihan dan shipment** · BALANCE = snapshot nilai unit eligible dikurangi bagian deposit. Untuk qty yang datang bertahap, bagi deposit per item ke tiap unit, floor quotient dan alokasikan sisa satu rupiah ke unit urutan awal. Invoice items menyimpan covered_qty; invoice yang hidup tidak boleh menagih cakupan unit yang sama dua kali. Receipt kumulatif menentukan jatah baru. Revisi invoice unpaid bila shortage. Untuk ongkir, buat paket PENDING dari unit eligible, hitung quote dari snapshot paket, lalu ajukan charge consent berreferensi paket. Biaya impor/EMS mengacu item dan qty. Customer accept sebelum issue ADDITIONAL_CHARGE. Paket yang diedit wajib menerima quote/consent baru; bila charge sudah lunas, gunakan pembatalan atau rekonsiliasi yang diaudit. Simpan snapshot breakdown, tax/EMS dan tarif. Kuantitas yang dapat dikirim sudah diterima, tagihan awal/termin item dan charge paket sudah lunas. Charge consent berstatus REJECTED/EXPIRED kembali ke antrean staf dan tidak menghasilkan invoice. Shipment partial dapat berlangsung tanpa menunggu batch lengkap atau pembeli lain.
- **Event dan notifikasi** · PreorderConfirmed, PreorderEtaUpdated, ProcurementStageChanged, InvoiceIssued; dashboard meminta tindakan saat ETA atau shortfall berubah dan email menyampaikan perubahan yang relevan.
- **Gagal/pulih** · QUOTA_FULL tanpa order separuh. Extension OPEN dilakukan sebelum jatuh tempo dengan audit. Partial failure menambah failed_qty/cancelled_qty, membatalkan kewajiban belum bayar untuk qty itu dan membuka review refund bila dana item telah diterima. Total failure membatalkan order sesuai keputusan refund, status alokasi FAILED. Receipt PO/RO tercatat di pengadaan, belum menjadi stok jual.

## Request order dan quotation

- **Aktor dan prasyarat** · Customer verified dan staff berizin. Request memiliki spesifikasi dan qty. Lampiran masuk koleksi privat.
- **Langkah dan data** · Buat request SUBMITTED dan item, staff claim UNDER_REVIEW. NEED_INFORMATION memakai thread customer. Staff membuat versi quotation DRAFT, kirim SENT dan set request QUOTED. Revisi pelanggan mengatur REVISION_REQUESTED serta supersede quotation. Accept mengunci request, versi SENT dan alamat, memeriksa expiry dengan DB time, mengubah quotation ACCEPTED, request CONVERTED_TO_ORDER, dan membuat order/initial invoice/snapshots atomik. Cart tidak terlibat. Reject menutup versi dan mengembalikan request ke review.
- **Event dan notifikasi** · RequestOrderSubmitted → staff. QuotationSent/Responded, QuotationAccepted, OrderCreated, InvoiceIssued → inbox/email. Setelah initial verified, pengadaan RO batch per request, mengikuti mekanisme receipt, consent dan termin yang sama dengan preorder.
- **Gagal/pulih** · Versi lama atau melewati valid_until → QUOTE_EXPIRED/INVALID_STATE_TRANSITION. Scheduler menutup versi expired dan request QUOTED yang tidak punya versi aktif. Accept ganda replay hasil yang sama jika parameter sesuai, unique request/order menutup jalur balapan.

## Manual payment dan verifikasi

- **Aktor dan prasyarat** · Customer memiliki invoice ISSUED dan akun bank aktif; finance wajib MFA dan permission review/verify. Ekspektasi transfer adalah jumlah sisa invoice yang dipilih. Daftar boleh mencakup beberapa order pembeli dan satu currency. Satu invoice hanya punya satu intent hidup pada satu waktu, diverifikasi saat invoice terkunci.
- **Langkah dan data** · Buat payment UNPAID dengan snapshot instruksi, expires_at paling awal dari due/hold invoice yang dipilih. Upload → scan → proof READY → submit; lock order/payment/hold dan, bila masih valid, set WAITING_CONFIRMATION dan extend hold ke maksimum submit + 24 jam. Perpanjangan hanya saat bukti valid pertama. Perpanjangan menyesuaikan payment.expires_at dan hold yang terkait. Finance claim UNDER_REVIEW dengan lease 15 menit (ASSUMPTION), cocokkan mutasi dan referensi, approve/reject. Approve menyimpan capture, allocations, invoice PAID dan initial order CONFIRMED, commit stok/kuota serta histori atomik. Tidak ada approve berdasarkan screenshot saja.
- **Event dan notifikasi** · PaymentSubmitted ke antrean finance, PaymentApproved/Rejected dan OrderPaid ke customer, InvoiceIssued memicu render PDF dan email ketika tagihan terbit.
- **Gagal/pulih** · Reject mencatat reason; intent baru bila expiry/hold masih memungkinkan. Recovery untuk transfer terlambat, ganda atau nominal berbeda mencatat payment_reconciliations OPEN dan tidak otomatis mengakui capture. Checker yang berbeda dari opener harus APPROVE sebelum APPLY/RETURN. REJECT mencatat alasan dan menutup tindakan hingga review baru oleh checker. Finance APPLY hanya bila exact amount dan semua order/invoice dapat dipulihkan bersama. Untuk initial expired, reacquire RTS/PO hold secara atomik pada harga/terms lama dan campaign policy masih mengizinkan; invoice awal tetap ISSUED selama late-payment window 7 hari (ASSUMPTION). Invoice awal EXPIRED hanya dapat dibayar melalui recovery. Setelah window itu invoice di-void; settlement lama tidak dapat apply. Bila tidak layak, finance RETURN mencatat transfer balik exception beserta bukti, tanpa menambah revenue. Role terpisah atau approval kedua untuk recovery sesuai security.

## Shipping dan tracking

- **Aktor dan prasyarat** · Warehouse berizin, paket per order, alamat snapshot dan eligible qty. Pelunasan wajib sebelum dispatch.
- **Langkah dan data** · Staff buat shipments PENDING dan shipment_items; packing-events menandai PACKING, PACKED, READY_FOR_DISPATCH. Paket tambahan memiliki packing_status sendiri sehingga order aggregate tidak mundur. Input courier service dan resi untuk dispatch setelah readiness dan pembayaran terpenuhi. Resi unik dalam carrier sejauh kontrak carrier menjamin (normalisasi). Dispatch memeriksa qty dan kewajiban lagi, membuat tracking event, menyimpan shipped_at dan SALE RTS sekali. Satu paket replacement memiliki return reference dan status terpisah.
- **Tracking dan notifikasi** · Manual event append-only di MVP. Adapter Phase 2 memverifikasi webhook, simpan raw event, normalisasi status dan insert unique tracking event. Job polling untuk paket belum terminal bila webhook terlambat; respect Retry-After, backoff, per-provider limits. Simpan raw/UNKNOWN di histori tanpa regresi status. ShipmentStatusUpdated dan OrderDelivered memberi update.
- **Gagal/pulih** · Paket sebelum dispatch bisa cancel dan lepaskan claim. FAILED dengan reason masuk antrean tracking; event provider baru yang valid boleh memulihkan, data lama tetap histori. Provider outage tidak memblokir resi manual. RETURNED dari kurir memicu review ulang pengiriman atau refund; stok kembali tersedia hanya setelah warehouse inspeksi melalui return/adjustment.

## Cancellation, reservation expiration dan completion

- **Aktor dan prasyarat** · Customer owner/staff berizin untuk cancel dan selesai, scheduler untuk expiry. Order tanpa capture dan tanpa proof review aktif dapat cancel langsung.
- **Langkah dan data** · Lock order dan hold, cancel payment intent belum settled, void invoice belum settle, release stok atau kuota. Timer mengklaim kandidat expired dengan lease/skip-locked, lalu kunci order dan resource dalam urutan standar serta recheck due/status; ACTIVE/RESERVED dilepas sekali. Jika capture sudah ada, buat refund review dengan cancel_order yang hanya menutup unit belum dispatch setelah keputusan. Pembayaran terlambat masuk reconciliation, bukan revive otomatis.
- **Status, event dan notifikasi** · PENDING_PAYMENT → EXPIRED/CANCELLED, OrderExpired/Cancelled, StockReleased atau PreorderAllocationReleased. COMPLETE saat semua qty non-cancelled delivered, semua kewajiban sudah resolved, dan tanpa dispute terbuka. Pembeli confirm atau scheduler setelah 72 jam (ASSUMPTION) dari delivery terakhir dan setelah window return yang berlaku, mana yang lebih lama. OrderCompleted ke inbox.
- **Gagal/pulih** · Expiry yang kalah race dengan approval menjadi no-op. Order yang sudah completed masih menerima after-sales sesuai window dan kebijakan. Kasus pembayaran tidak dihapus.

## Refund

- **Aktor dan prasyarat** · Customer/CS, reviewer finance dan eksekutor finance. Ada captured funds, alasan aktif serta cap per order, invoice, allocation dan item.
- **Langkah dan data** · Minta FULL/PARTIAL/ITEM, opsional evidence. Review under lock atas order/capture, approve amount dan hold cap, atau reject dengan alasan. Eksekutor memastikan belum pernah transfer, lalu memulai PROCESSING dan mengeksekusi transfer manual di luar sistem. Submit bukti READY dan bank reference, kunci source allocations, tulis refund_transactions dan COMPLETED. Satu actual bank transfer bisa mencakup beberapa allocation rows, tapi satu refund transfer ref disimpan sebagai satu grup.
- **Status, event dan notifikasi** · RefundRequested, RefundApproved, RefundCompleted atau RefundRejected. Perbarui payment/order aggregate. cancel_order melepaskan qty yang belum dispatch dan menutup order setelah kewajiban diselesaikan, dengan ledger dan audit.
- **Gagal/pulih** · FAILED tidak melepas cap sampai mutasi bank jelas. Bila transfer sudah terjadi namun respons hilang, retry pencatatan dengan reference sama. REFUND_LIMIT untuk melebihi cap. Dana ganda yang sengaja tidak dicapture dipulangkan melalui jalur reconciliation RETURN.

## Return

- **Aktor dan prasyarat** · Customer owner, CS dan warehouse, shipment delivered, dalam 48 jam (konfigurasi), qty belum diklaim/belum dikembalikan.
- **Langkah dan data** · Tulis record per shipment, foto barang dan label, video bila ada, inspeksi kelengkapan sebelum approval. Admin memberi instruksi atau waive shipment beralasan. Customer input resi, warehouse menerima, inspeksi per item dan disposition. RTS layak jual masuk RETURN ledger, barang damage dicatat terpisah. Resolusi refund membuat satu refund terkait, replacement/shortage membuat shipment pengganti, atau pekerjaan repair dicatat dan dikonfirmasi CS.
- **Status dan event** · REQUESTED → UNDER_REVIEW → APPROVED → AWAITING_SHIPMENT → IN_TRANSIT → RECEIVED → INSPECTING → ACCEPTED → RESOLVED. INSPECTION_FAILED boleh resolve REJECTED. ReturnRequested, ReturnReviewed, ReturnInspected, ReturnResolved memicu pesan status.
- **Gagal/pulih** · Window tutup → RETURN_WINDOW_CLOSED, qty konflik → QUANTITY_CONFLICT. Instruksi return berisi tujuan dan tenggat; CS menangani resi hilang tanpa mengubah hasil inspeksi. Refund/repair/replacement yang belum selesai menahan resolusi kasus.

## Inventory reservation dan ledger

Untuk Phase 2, satu promo per baris: kumpulkan promo eligible aktif dan kupon, urutkan priority menurun lalu ID naik, pakai yang pertama memenuhi syarat. Cap diskon tetap pada harga eligible, pembulatan half-up per baris. Order term tidak mengubah basis diskon. Hold coupon pada checkout, finalize saat initial invoices pada grup eligible PAID, release jika seluruh grup eligible batal/kedaluwarsa tanpa capture.

Default lock lintas modul: cart bila checkout, request/quotation bila konversi, order ID ascending bila sudah ada, child invoice/payment/refund/shipment ascending menurut jenis yang digunakan, campaign variant/coupon, inventory level ascending, dan bank receipt account terakhir saat settlement. Antar action yang memakai aggregate sama harus mengikuti urutan yang sama. Expiry mengambil kandidat tanpa child lock yang bertahan sebelum parent order lock; penggunaan SKIP LOCKED pada parent atau tabel kerja. Semua hitung ulang dengan DB time. PostgreSQL READ COMMITTED dengan lock eksplisit/conditional update; tidak ada request provider atau file copy dalam lock.

| Perintah | Ledger delta (on_hand, reserved, committed) | Kondisi |
|---|---|---|
| INITIAL/PURCHASE/ADJUSTMENT_IN | +q, 0, 0 | Sumber dan alasan sah |
| ADJUSTMENT_OUT/DAMAGE | -q, 0, 0 | q ≤ available |
| RESERVATION | 0, +q, 0 | q ≤ available dan TTL aktif |
| RESERVATION_RELEASE | 0, -q, 0 | Hold ACTIVE belum digunakan |
| COMMIT | 0, -q, +q | Capture invoice awal RTS verified |
| COMMIT_RELEASE | 0, 0, -q | Pembatalan qty belum dispatch |
| SALE | -q, 0, -q | Dispatch valid; source = shipment item |
| RETURN | +q, 0, 0 | Unit RTS diterima dan layak jual |
| TRANSFER_OUT/TRANSFER_IN | -q/+q, 0, 0 | Phase 2, level varian sama, satu transaksi |

## Aturan status

Tabel ini lengkap untuk status yang diwakili kolom State di skema. Status awal adalah yang pertama ditulis, kecuali Fulfillment dimulai UNFULFILLED dan OrderPayment UNPAID sebagaimana tercantum. Setiap transisi membutuhkan command/domain actor yang sesuai, dan histori prev/next. Edge tak terdaftar → 409. Perintah yang telah diproses hanya replay hasil bila intent sama. State terminal tidak diubah melalui editor biasa. Pemulihan yang terdaftar memerlukan alasan, actor dan event.

### Order

CONFIRMED membutuhkan invoice awal PAID (termasuk DP). EXPIRED → CONFIRMED hanya recovery finance setelah seluruh supply dan invoice tervalidasi kembali. CONFIRMED → CANCELLED setelah semua unit belum dispatch dan retained/refunded amounts disetujui. COMPLETED tidak menutup after-sales.

| Asal | Tujuan diizinkan |
|---|---|
| PENDING_PAYMENT | CONFIRMED, CANCELLED, EXPIRED |
| CONFIRMED | COMPLETED, CANCELLED |
| EXPIRED | CONFIRMED |

Status terminal · CANCELLED, COMPLETED.

### OrderPayment

Projection, tidak dapat dimutasi langsung. UNPAID sebelum capture. PARTIALLY_PAID jika termin, biaya approved, atau invoice current belum terpenuhi. PARTIALLY_REFUNDED dan REFUNDED memakai refund selesai terhadap capture saat kewajiban aktif terpenuhi atau order ditutup. Capture baru bisa memulai projection PAID/PARTIALLY_PAID. Histori refund tetap tersimpan.

| Asal | Tujuan diizinkan |
|---|---|
| UNPAID | PARTIALLY_PAID, PAID |
| PARTIALLY_PAID | PAID, PARTIALLY_REFUNDED, REFUNDED |
| PAID | PARTIALLY_PAID, PARTIALLY_REFUNDED, REFUNDED |
| PARTIALLY_REFUNDED | PARTIALLY_PAID, PAID, REFUNDED |
| REFUNDED | PARTIALLY_PAID, PAID |

Status terminal · projection dapat berubah melalui domain event.

### Fulfillment

Order projection. PO/RO AWAITING_STOCK selama pengadaan; per unit eligible walaupun aggregate belum seluruhnya siap. Siklus packing lanjutan setelah partial dispatch berlangsung pada paket; aggregate tidak mundur. DELIVERED hanya seluruh qty non-cancelled delivered. RETURNED hanya bila seluruh unit sebelumnya delivered berhasil diretur.

| Asal | Tujuan diizinkan |
|---|---|
| UNFULFILLED | AWAITING_STOCK, READY_TO_PACK |
| AWAITING_STOCK | READY_TO_PACK |
| READY_TO_PACK | PACKING |
| PACKING | PACKED |
| PACKED | READY_FOR_DISPATCH |
| READY_FOR_DISPATCH | PARTIALLY_SHIPPED, SHIPPED |
| PARTIALLY_SHIPPED | SHIPPED, DELIVERED |
| SHIPPED | DELIVERED |
| DELIVERED | RETURNED |

Status terminal · RETURNED.

### StockReservation

ACTIVE ber-TTL; COMMITTED mewakili saldo sisa yang belum ship/release. CONSUMED setelah sisa nol dan ada SALE, RELEASED jika semua dilepas tanpa jual. Partial sale/release mencatat qty delta tanpa mengubah status sampai sisa nol.

| Asal | Tujuan diizinkan |
|---|---|
| ACTIVE | COMMITTED, RELEASED, EXPIRED |
| COMMITTED | RELEASED, CONSUMED |

Status terminal · RELEASED, EXPIRED, CONSUMED.

### Preorder

Scheduler membuka/tutup sesuai window. OPEN boleh diperpanjang sebelum ends_at, dan CLOSED hanya dapat dimulai ke procurement atau dibatalkan. Cancel memproses tiap alokasi affected. COMPLETE setelah semua alokasi final.

| Asal | Tujuan diizinkan |
|---|---|
| DRAFT | SCHEDULED, CANCELLED |
| SCHEDULED | OPEN, CANCELLED; khusus scheduler: langsung CLOSED jika seluruh window sudah terlewat tanpa pernah dibuka |
| OPEN | CLOSED, CANCELLED |
| CLOSED | IN_PROCUREMENT, CANCELLED |
| IN_PROCUREMENT | FULFILLING, CANCELLED |
| FULFILLING | COMPLETED, CANCELLED |

Status terminal · CANCELLED, COMPLETED.

Jika tidak ada unit yang pernah diterima dan seluruh unit pesanan berbayar sudah gagal final, staf dapat membatalkan *kampanye* dari IN_PROCUREMENT. Pesanan berbayar, invoice dan hold tetap menunggu keputusan refund; pembatalan kampanye tidak mencatat penerimaan atau mengembalikan dana. Satu unit berbayar yang masih bisa dipenuhi tetap memblokir pembatalan.

### PreorderAllocation

RESERVED mengikuti hold. CONFIRMED sesudah initial invoice PAID; FAILED bila seluruh sisa pemasok gagal, CANCELLED bila pembeli/operator membatalkan seluruh alokasi. Partial failure dicatat via failed_qty dan jadikan FULFILLED saat sisa sukses final.

| Asal | Tujuan diizinkan |
|---|---|
| RESERVED | CONFIRMED, RELEASED, EXPIRED |
| CONFIRMED | FULFILLED, CANCELLED, FAILED |

Status terminal · RELEASED, EXPIRED, FULFILLED, CANCELLED, FAILED.

### Procurement

Receipt sebagian boleh ketika IN_PROGRESS; close hanya semua baris resolved, termasuk terminal failure dengan reason.

Bedakan **gagal pemasok yang akan dicoba lagi** dari **kekurangan final untuk pembeli**. Untuk mencoba pemasok lain, ubah stage ke FAILED lalu batalkan batch dengan alasan; hanya sisa yang belum diterima/gagal yang bebas ditugaskan lagi. Untuk shortage final, kirim `failed_qty` dengan alasan aktif domain `procurement`. Unit tersebut menjadi `cancelled_qty`, bukan stok bebas. Satu hold DISPUTE dan event `SupplierShortageReviewRequested` per baris receipt mengikat review ke pesanan yang terdampak. TASK-018 tidak mentransfer uang, menerbitkan pelunasan, atau menganggap unit yang diterima sudah dikirim. Refunds melepas hold sumbernya setelah keputusan; Invoicing/Shipping membaca qty kumulatif per item.

| Asal | Tujuan diizinkan |
|---|---|
| OPEN | IN_PROGRESS, CANCELLED |
| IN_PROGRESS | RECEIVED, CLOSED, CANCELLED |
| RECEIVED | CLOSED |

Status terminal · CANCELLED, CLOSED.

### ProcurementStage

Milestone maju dengan bukti/catatan. RECEIVED dan FAILED terminal pada stage; retry supplier membuat batch baru untuk qty yang telah dilepas dari batch lama dengan audit. Cancel batch sebelum receipt final tidak membuat stok RTS.

| Asal | Tujuan diizinkan |
|---|---|
| PENDING_PURCHASE | PURCHASED, FAILED |
| PURCHASED | SELLER_SHIPPED, FAILED |
| SELLER_SHIPPED | AT_ORIGIN_WAREHOUSE, INTERNATIONAL_TRANSIT, FAILED |
| AT_ORIGIN_WAREHOUSE | INTERNATIONAL_TRANSIT, FAILED |
| INTERNATIONAL_TRANSIT | AT_DESTINATION_WAREHOUSE, FAILED |
| AT_DESTINATION_WAREHOUSE | IMPORT_FEE_ASSESSMENT, RECEIVED, FAILED |
| IMPORT_FEE_ASSESSMENT | RECEIVED, FAILED |

Status terminal · FAILED, RECEIVED.

### RequestOrder

Quotation reject/withdraw mengembalikan request ke review; revise bekerja via message dan versi baru. Reopen EXPIRED memerlukan staff dan histori. Conversion sekali.

| Asal | Tujuan diizinkan |
|---|---|
| SUBMITTED | UNDER_REVIEW, REJECTED, CANCELLED |
| UNDER_REVIEW | NEED_INFORMATION, QUOTED, REJECTED, CANCELLED |
| NEED_INFORMATION | UNDER_REVIEW, CANCELLED |
| QUOTED | REVISION_REQUESTED, CONVERTED_TO_ORDER, EXPIRED, CANCELLED, UNDER_REVIEW |
| REVISION_REQUESTED | UNDER_REVIEW, QUOTED, CANCELLED |
| EXPIRED | UNDER_REVIEW, CANCELLED |

Status terminal · REJECTED, CANCELLED, CONVERTED_TO_ORDER.

### Quotation

SENT immutable. Versi baru supersede versi SENT lama di transaksi yang sama. Acceptance memeriksa validity dan request.

FULL menagih seluruh nilai barang. DEPOSIT membagi DP secara proporsional terhadap nilai setiap baris menurut urutan item, dengan selisih pembulatan ditentukan dari total kumulatif sehingga tidak ada rupiah hilang. Unit awal di baris menerima sisa pembagian. Estimasi ongkir tidak ditambahkan ke invoice awal. Tidak ada hold stok atau kuota saat konversi.

| Asal | Tujuan diizinkan |
|---|---|
| DRAFT | SENT, WITHDRAWN |
| SENT | ACCEPTED, REJECTED, EXPIRED, SUPERSEDED, WITHDRAWN |

Status terminal · WITHDRAWN, ACCEPTED, REJECTED, EXPIRED, SUPERSEDED.

### Invoice

PAID dan VOID terminal; refund tidak mengubah invoice asli. VOID hanya bila belum ada applied payment; revision menghasilkan nomor baru.

| Asal | Tujuan diizinkan |
|---|---|
| DRAFT | ISSUED, VOID |
| ISSUED | PAID, VOID |

Status terminal · VOID, PAID.

### Payment

WAITING_CONFIRMATION berarti proof READY telah dikirim. Review claim lease. Expired recovery berizin dan harus memulihkan hold secara atomik sebelum PAID. REJECTED terminal untuk intent normal; receipt actual yang salah alokasi ikut reconciliation. REFUNDED berdasarkan cap capture per payment.

| Asal | Tujuan diizinkan |
|---|---|
| UNPAID | WAITING_CONFIRMATION, EXPIRED, CANCELLED |
| WAITING_CONFIRMATION | UNDER_REVIEW, EXPIRED |
| UNDER_REVIEW | WAITING_CONFIRMATION, PAID, REJECTED, EXPIRED |
| EXPIRED | UNDER_REVIEW |
| PAID | PARTIALLY_REFUNDED, REFUNDED |
| PARTIALLY_REFUNDED | REFUNDED |

Status terminal · CANCELLED, REJECTED, REFUNDED.

### Shipment

DISPATCH mencatat PICKED_UP dan SALE RTS secara atomik. Status normalized maju, FAILED boleh dipulihkan dengan event baru. EXTERNALLY_FULFILLED Phase 2 diperlakukan delivered hanya dengan bukti konfirmasi yang memiliki timestamp.

| Asal | Tujuan diizinkan |
|---|---|
| PENDING | PICKUP_REQUESTED, PICKED_UP, CANCELLED, EXTERNALLY_FULFILLED |
| PICKUP_REQUESTED | PICKED_UP, CANCELLED |
| PICKED_UP | IN_TRANSIT, OUT_FOR_DELIVERY, DELIVERED, FAILED, RETURNED |
| IN_TRANSIT | OUT_FOR_DELIVERY, DELIVERED, FAILED, RETURNED |
| OUT_FOR_DELIVERY | DELIVERED, FAILED, RETURNED |
| FAILED | IN_TRANSIT, OUT_FOR_DELIVERY, DELIVERED, RETURNED |

Status terminal · CANCELLED, EXTERNALLY_FULFILLED, DELIVERED, RETURNED.

### Refund

Approve hold cap. START dan RETRY membutuhkan cek bahwa transfer sebelumnya belum selesai, FAIL menahan cap dan perlu rekonsiliasi. Refund COMPLETE satu kali setelah bukti transfer; pembatalan setelah APPROVED/FAILED memerlukan verifikasi tidak ada transfer.

| Asal | Tujuan diizinkan |
|---|---|
| REQUESTED | UNDER_REVIEW, CANCELLED |
| UNDER_REVIEW | APPROVED, REJECTED, CANCELLED |
| APPROVED | PROCESSING, CANCELLED |
| PROCESSING | COMPLETED, FAILED |
| FAILED | PROCESSING, CANCELLED |

Status terminal · CANCELLED, REJECTED, COMPLETED.

### Return

WAIVE_SHIPMENT hanya untuk klaim tanpa barang balik dengan alasan, langsung INSPECTING. Inspection failure lalu RESOLVED dengan REJECTED. Refund/reship/repair dituntaskan sebelum resolusi final.

| Asal | Tujuan diizinkan |
|---|---|
| REQUESTED | UNDER_REVIEW, CANCELLED |
| UNDER_REVIEW | APPROVED, REJECTED, CANCELLED |
| APPROVED | AWAITING_SHIPMENT, INSPECTING, CANCELLED |
| AWAITING_SHIPMENT | IN_TRANSIT, CANCELLED |
| IN_TRANSIT | RECEIVED |
| RECEIVED | INSPECTING |
| INSPECTING | ACCEPTED, INSPECTION_FAILED |
| ACCEPTED | RESOLVED |
| INSPECTION_FAILED | RESOLVED |

Status terminal · CANCELLED, REJECTED, RESOLVED.

## Analisis persaingan

Semua kasus memakai transaksi singkat. Retry maksimal tiga kali dengan jitter untuk deadlock/serialization failure; domain conflict dikembalikan agar user meninjau kondisi terbaru. Untuk DB constraint race, reload record dan konfirmasi source/hash sebelum replay.

| Skenario dan risiko | Strategi DB/lock dan batas transaksi | Constraint | Idempotensi dan retry |
|---|---|---|---|
| Concurrent checkout cart sama → order ganda | Lock cart version, validasi, allocate serta insert checkout/orders/invoice atomik | checkout(cart_id,cart_version), partial unique RTS/PO | Key wajib; concurrent attempt membaca replay, version lama konflik |
| Last SKU, hold bersaing expiry/approve → oversell | Lock order lalu level; conditional update available, ledger dan state commit satu transaksi; recheck TTL | Nonnegative, reserved+committed ≤ on_hand, movement unique source | Key checkout, expiry source deterministic, retry DB transient |
| Kuota terakhir PO / max akun | Lock campaign then variant, cek scope akun, reserve dan allocation satu transaksi | quota cap, allocation satu per item | Replay checkout, quota conflict membutuhkan perubahan cart |
| Coupon global/per akun Phase 2 | Lock coupon row, cek HELD+USED dan akun, insert usage dalam checkout | usage(coupon,checkout), aggregate cap | Key checkout, expiry release sekali |
| Approval payment dua kali atau invoice sama | Lock order, invoice, payment dan rekening; verifikasi claim, exact receipt, apply dan commit supply atomik | Unique bank ref lintas jalur via bank lock, allocations(payment,invoice) | Key verify, repeated action sama replay; mismatch diekskalasi |
| Refund ganda dan transfer ambigu | Lock order/allocations, reserve cap saat approve, execute manual lalu finalize di transaksi lain | Unique refund/return dan transfer group, cap di bawah lock | Key per fase; reconcile transfer sehingga retry tidak mengirim uang lagi |
| Adjustment bersaing dispatch/hold | Lock level; validate available, adjust, audit dan movement commit bersama | unique movement source, saldo nonnegative | Key adjustment, conflicting qty ditolak |
| Nomor transaction dibuat bersamaan | nextval per aggregate di transaksi insert | Unique business number, gaps diterima | Business source unik, retry tidak membuat data kedua |
| Invoice berulang dan PDF job duplikat | Lock order, derive source_ref, issue dan outbox atomik; render terpisah idempotent | invoice(order,source_ref), replaces_invoice unique | Key issue + unique event handler; PDF object key stabil |
| Dua tindakan quotation | Lock request lalu versi, recheck masa berlaku, convert dan issue atomik | Satu SENT, satu ACCEPTED, order(request_order_id) | Key accept; payload sama replay, versi lama konflik |
| Shipment event out-of-order/duplikat | Inbox durable, lock order dan shipment, status harus maju | webhook provider/event key, tracking(shipment,event_key) | Replay ack, polling dan manual menjaga stage |
| Return claim dan inspection ganda | Lock order/shipment items dan cek total active+resolved; inspeksi dan restock satu transaksi | return-item unique, movement unique by return item | Key claim/inspect, quantity conflict |
