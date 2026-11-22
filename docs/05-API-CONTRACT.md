# REST API contract

Semua path di bawah relatif terhadap **/api/v1**. Endpoint CSRF **GET /sanctum/csrf-cookie** dari framework diproxy same-origin, menghasilkan 204 dan XSRF cookie. Seluruh kontrak rencana di bawah ini diimplementasikan setelah approval. API memakai DTO dan projection eksplisit agar perubahan model internal tidak membocorkan field.

```json
{
  "success": true,
  "message": "Checkout created",
  "data": {"id": "01KAYZJH52C1QPM3A4N1YDF5F8", "order_numbers": ["ORD-20261122-000001"]},
  "meta": {"request_id": "d85ae781-f7be-4a89-b98a-0f388359f9b3"}
}
```

```json
{
  "success": false,
  "message": "Validation failed",
  "code": "VALIDATION_FAILED",
  "errors": {"address_id": ["Alamat tidak aktif."]},
  "meta": {"request_id": "d85ae781-f7be-4a89-b98a-0f388359f9b3"}
}
```

CrowdFunding memakai campuran data/message, respons JSON inline, serta error Laravel atau error provider. Snowego menormalisasi envelope di atas. Redirect OAuth, metrics plaintext, dan 204 Sanctum mengikuti protokol masing-masing. Untuk Page dan CursorPage, data adalah array, meta berisi page/per_page/total atau next_cursor. Ack dan AuthAck memakai data null. GET/PATCH/PUT/DELETE sukses 200. POST untuk resource baru 201 (cart item upsert dan proof juga 201); POST action, preview dan auth 200; POST file, region-imports, pdf-requests, sync-requests dan webhook valid 202; OAuth 302. Respons error memiliki request ID; public GET boleh cache hanya setelah membuang data sesi.

## Auth, ownership dan idempotency

| Permukaan | Auth dan permission default |
|---|---|
| Public | Anonim dan rate limited, hanya data terbit |
| Auth | Anonim dengan CSRF untuk mutasi, guest session untuk OAuth; auth throttling |
| Session | Sanctum sesuai surface dan owner; daftar/revoke hanya perangkat sendiri |
| Customer | Sanctum COOKIE + CUSTOMER + verified email dan ownership. Pengecualian verifikasi ulang email dan pengaitan Google menerima akun belum verified. |
| Admin auth | Anonim saat login; MFA memerlukan pre-auth challenge; enrollment memerlukan undangan yang valid; step-up memerlukan sesi STAFF aktif dan MFA |
| Admin | Sanctum COOKIE + STAFF aktif + TOTP verified + permission yang tercantum; policy bisnis dan scope file parent tetap dicek |
| Webhook | Signature/replay policy provider dan validasi raw event, tanpa sesi browser |
| Internal | Jaringan privat dan allowlist/identitas service, tanpa paparan ingress publik |

Path params: slug berupa huruf lower-case, angka dan hyphen (maks 200); number dan quotation_number adalah nomor bisnis dari schema; id pembeli adalah ULID kecuali session_ref opaque dan slug; id admin numerik positif kecuali files memakai ULID; key merupakan code/allowlist, provider terdaftar; action whitelist menurut tujuan. Kombinasi query/mutation di luar kontrak ditolak. Query page default 1, per_page default 20 maks 100. Cursor opaque; q 1–120 karakter; from/to ISO tanggal/waktu, rentang laporan maks 92 hari. Status/method typed enum sesuai resource, sort katalog -published_at atau name, include order items,invoices,payments,shipments,history. Query referensi memakai nomor/kode/ULID sesuai resource. Semua field body wajib kecuali tanda ?. Partial membutuhkan minimal satu field. Field asing ditolak.

K berarti Idempotency-Key wajib, 1–128 karakter ASCII visible. Scope = actor/route template + method; hash canonical request mencakup path konkret, query dan body, serta sha256 konten file. Key dan respons terminal commit bersama mutasi DB; claim PROCESSING ber-lease diperoleh dahulu, transaksi mutasi memegang lock claim, dan retry hanya mengambil lease kedaluwarsa. Payload berbeda → 422 IDEMPOTENCY_KEY_REUSED, claim berjalan → 409 REQUEST_IN_PROGRESS. Scope auth/ownership diperiksa sebelum replay. Kegagalan 5xx/transient dapat diulang; TTL minimum 48 jam. Unique constraint bisnis tetap berlaku setelah TTL. Untuk endpoint non-K, GET/PUT/DELETE mengikuti semantik HTTP. Login/logout dan challenge memiliki single-use token/sesi. Webhook dedup permanen per provider/event. Checkout juga memakai version cart; update intent yang sudah diproses dengan key berbeda mengembalikan hasil existing hanya bila source, action dan payload relevan identik, selain itu 409.

## Kesalahan bersama

| HTTP | Kode |
|---|---|
| 400 | INVALID_SIGNATURE, INVALID_PROVIDER_EVENT (respons webhook minimal) |
| 401 | UNAUTHENTICATED, BAD_CREDENTIALS |
| 403 | FORBIDDEN, EMAIL_NOT_VERIFIED, MFA_REQUIRED, STEP_UP_REQUIRED |
| 404 | NOT_FOUND, juga untuk akses ID milik customer lain |
| 409 | VERSION_CONFLICT, QUOTE_CHANGED, QUOTE_EXPIRED, OUT_OF_STOCK, QUOTA_FULL, INVALID_STATE_TRANSITION, TRANSFER_CONFLICT, REFUND_LIMIT, RETURN_WINDOW_CLOSED, QUANTITY_CONFLICT, IDENTITY_CONFLICT, FILE_NOT_READY, REQUEST_IN_PROGRESS, DUPLICATE_RESOURCE, OFFER_UNAVAILABLE, INVOICE_NOT_PAYABLE, NO_SHIPPING_RATE |
| 413/415 | UPLOAD_REJECTED sesuai ukuran/tipe transport |
| 419 | CSRF_TOKEN_MISMATCH |
| 422 | VALIDATION_FAILED, INVALID_CHALLENGE, IDEMPOTENCY_KEY_REUSED |
| 429 | THROTTLED dengan Retry-After |
| 503 | DEPENDENCY_UNAVAILABLE dengan Retry-After bila tersedia |
| 500 | INTERNAL_ERROR, pesan umum dan request ID |

Tabel endpoint menambahkan error khusus. Validasi dasar, error autentikasi/otorisasi, CSRF, rate limit, dan 5xx berlaku global. Efek berupa operasi sinkron; daftar event dipublikasikan via outbox setelah commit, kecuali handshake sesi langsung dan event webhook setelah inbox durable.

## Tipe request dan response

Lihat [kamus tipe dan validasi](API-DATA-TYPES.md). Setiap response bertipe resource dibungkus envelope di atas.

## Public API

| ID | Method dan path | Tujuan | Auth / permission | Body | Query | Respons | Error khusus | Efek | Event | Idempotensi |
|---|---|---|---|---|---|---|---|---|---|---|
| API-FND-001 | `GET /health` | Liveness publik | public | – | – | Health | – | – | – | – |
| API-CAT-001 | `GET /products` | Daftar katalog | public | – | Page,q?,category?,brand?,collection?,tag?,method?,sort? | Page<Product> | VALIDATION_FAILED | – | – | – |
| API-CAT-002 | `GET /products/{slug}` | Detail, varian dan penawaran | public | – | – | ProductDetail | NOT_FOUND | – | – | – |
| API-CAT-003 | `GET /categories` | Daftar kategori | public | – | Page,parent? | Page<Category> | VALIDATION_FAILED | – | – | – |
| API-SRC-001 | `GET /search` | Cari katalog terbit | public | – | Page,q,category?,method? | Page<Product> | VALIDATION_FAILED | – | – | – |
| API-PRE-001 | `GET /preorders` | Daftar kampanye | public | – | Page,status? | Page<Campaign> | VALIDATION_FAILED | – | – | – |
| API-PRE-002 | `GET /preorders/{slug}` | Detail jadwal dan kuota | public | – | – | CampaignDetail | NOT_FOUND | – | – | – |
| API-CNT-001 | `GET /content/{slug}` | Homepage/FAQ/kebijakan terbit | public | – | – | Content | NOT_FOUND | – | – | – |
| API-GEO-001 | `GET /regions` | Wilayah delivery | public | – | Page,parent?,kind? | Page<Region> | VALIDATION_FAILED | – | – | – |

## Customer API — identity

| ID | Method dan path | Tujuan | Auth / permission | Body | Query | Respons | Error khusus | Efek | Event | Idempotensi |
|---|---|---|---|---|---|---|---|---|---|---|
| API-AUTH-001 | `POST /auth/register` | Daftar pembeli | auth | name:text120,email:email,password:password | – | AuthAck | VALIDATION_FAILED,THROTTLED | Buat pending, kirim verifikasi | EmailVerificationRequested | – |
| API-AUTH-002 | `POST /auth/login` | Login pembeli | auth | email:email,password:password | – | Me | BAD_CREDENTIALS,THROTTLED | Regenerasi sesi pembeli | – | – |
| API-AUTH-003 | `POST /auth/password-reset-requests` | Minta token reset | auth | email:email | – | AuthAck | THROTTLED | Queue email bila eligible, respons seragam | PasswordResetRequested | – |
| API-AUTH-004 | `POST /auth/password-resets` | Reset dengan token | auth | email:email,token:token,password:password | – | AuthAck | INVALID_CHALLENGE,THROTTLED | Rotasi password dan revoke sesi | PasswordReset | – |
| API-AUTH-005 | `POST /auth/email-verifications` | Validasi challenge email | auth | token:token | – | AuthAck | INVALID_CHALLENGE | Tandai verified sekali | EmailVerified | – |
| API-AUTH-006 | `POST /auth/email-verification-requests` | Kirim ulang verifikasi | customer | – | – | AuthAck | THROTTLED | Queue email jika belum verified | EmailVerificationRequested | – |
| API-AUTH-007 | `GET /auth/google/redirect` | Mulai OAuth | auth | – | – | Redirect302 | THROTTLED | Simpan state single-use di sesi | – | – |
| API-AUTH-008 | `GET /auth/google/callback` | Selesaikan Google OAuth | auth | – | code?,state,error? | Redirect302 | INVALID_CHALLENGE,IDENTITY_CONFLICT | Login akun terhubung, provision verified atau mulai link challenge | GoogleIdentityVerified | – |
| API-AUTH-009 | `POST /auth/google/links` | Konfirmasi link akun yang sudah ada | customer | token:token,password:password | – | Me | IDENTITY_CONFLICT,INVALID_CHALLENGE | Bukti akses akun, konsumsi challenge dan rotasi sesi | GoogleAccountLinked | – |
| API-AUTH-010 | `GET /auth/me` | Lihat sesi saat ini | session | – | – | Me | – | – | – | – |
| API-AUTH-011 | `POST /auth/logout` | Keluar dari sesi | session | – | – | AuthAck | – | Revoke sesi dan rotasi CSRF | – | – |
| API-AUTH-012 | `GET /auth/sessions` | Daftar perangkat aktif | session | – | Page | Page<Session> | – | – | – | – |
| API-AUTH-013 | `DELETE /auth/sessions/{session_ref}` | Revoke perangkat sendiri | session | – | – | AuthAck | NOT_FOUND | Revoke pada surface yang sama | SessionRevoked | – |
| API-CUS-001 | `GET /profile` | Baca profil | customer | – | – | Profile | – | – | – | – |
| API-CUS-002 | `PATCH /profile` | Edit profil | customer | display_name?:text120,phone?:phone,marketing_opt_in?:bool | – | Profile | VALIDATION_FAILED | Simpan field allowlisted | ProfileUpdated | – |
| API-CUS-003 | `GET /addresses` | Daftar alamat sendiri | customer | – | Page | Page<Address> | – | – | – | – |
| API-CUS-004 | `POST /addresses` | Tambah alamat | customer | AddressInput | – | Address | VALIDATION_FAILED | Tambah dan atur default atomik | AddressChanged | – |
| API-CUS-005 | `PATCH /addresses/{id}` | Edit alamat | customer | Partial<AddressInput> | – | Address | NOT_FOUND,VALIDATION_FAILED | Perbarui tanpa menyentuh snapshot | AddressChanged | – |
| API-CUS-006 | `DELETE /addresses/{id}` | Arsipkan alamat | customer | – | – | Ack | NOT_FOUND | Arsipkan; reset default secara deterministik | AddressChanged | – |
| API-CUS-007 | `GET /wishlist` | Daftar favorit | customer | – | Page | Page<Product> | – | – | – | – |
| API-CUS-008 | `PUT /wishlist/{slug}` | Simpan favorit | customer | – | – | Ack | NOT_FOUND | Upsert unique user/product | – | – |
| API-CUS-009 | `DELETE /wishlist/{slug}` | Hapus favorit | customer | – | – | Ack | – | Hapus idempotent | – | – |
| API-CRT-001 | `GET /cart` | Ambil cart aktif | customer | – | – | Cart | – | Tidak menulis data; virtual cart kosong version 0 | – | – |
| API-CRT-002 | `POST /cart/items` | Tambah atau set item | customer | CartItemInput | – | Cart | OFFER_UNAVAILABLE,VERSION_CONFLICT | Lock cart, upsert qty absolut dan naikkan version | CartChanged | – |
| API-CRT-003 | `PATCH /cart/items/{id}` | Ubah qty atau termin | customer | qty:qty,term?:term,cart_version:int | – | Cart | NOT_FOUND,VERSION_CONFLICT,OFFER_UNAVAILABLE | Lock cart dan naikkan version | CartChanged | – |
| API-CRT-004 | `DELETE /cart/items/{id}` | Hapus item | customer | – | cart_version | Cart | NOT_FOUND,VERSION_CONFLICT | Lock cart dan naikkan version | CartChanged | – |
| API-CHK-001 | `POST /checkout/previews` | Preview checkout dan tarif | customer | cart_version:int,address_id:ulid,shipping_quote_id?:ulid,coupon?:text80 | – | CheckoutPreview | VERSION_CONFLICT,OFFER_UNAVAILABLE,NO_SHIPPING_RATE | Simpan quote ber-TTL dan kembalikan hash untuk pilihan akhir | – | – |
| API-CHK-002 | `POST /checkout` | Submit checkout | customer | cart_version:int,address_id:ulid,shipping_quote_id?:ulid,quote_hash:hash,coupon?:text80,notes?:text1000 | – | Checkout | QUOTE_CHANGED,VERSION_CONFLICT,OUT_OF_STOCK,QUOTA_FULL | Reserve dan buat order/invoice/outbox atomik | StockReserved,OrderCreated,InvoiceIssued | K |
| API-CHK-003 | `GET /checkouts/{id}` | Baca hasil checkout | customer | – | – | Checkout | NOT_FOUND | – | – | – |
| API-ORD-001 | `GET /orders` | Daftar order sendiri | customer | – | Page,status?,method? | Page<Order> | – | – | – | – |
| API-ORD-002 | `GET /orders/{number}` | Detail dan timeline | customer | – | include? | OrderDetail | NOT_FOUND | – | – | – |
| API-ORD-003 | `POST /orders/{number}/cancellations` | Batalkan order tanpa capture | customer | reason_code:code,note?:text1000 | – | Order | INVALID_STATE_TRANSITION | Release hold dan void invoice; jika sudah bayar gunakan refund | OrderCancelled | K |
| API-ORD-004 | `POST /orders/{number}/completions` | Konfirmasi selesai | customer | – | – | Order | INVALID_STATE_TRANSITION | Cek semua paket final dan dispute | OrderCompleted | K |
| API-ORD-005 | `POST /orders/{number}/charge-consents/{id}/decisions` | Tanggapi biaya PO/RO | customer | decision:ACCEPT/REJECT | – | Consent | QUOTE_CHANGED,INVALID_STATE_TRANSITION | Terima dan issue invoice atau kembalikan ke antrean staff | InvoiceIssued/ChargeDeclined | K |
| API-IVC-001 | `GET /invoices` | Daftar tagihan | customer | – | Page,order?,status? | Page<Invoice> | – | – | – | – |
| API-IVC-002 | `GET /invoices/{number}` | Rincian invoice | customer | – | – | Invoice | NOT_FOUND | – | – | – |
| API-IVC-003 | `GET /invoices/{number}/pdf` | Link privat PDF | customer | – | – | Download | FILE_NOT_READY,NOT_FOUND | Otorisasi dan terbitkan URL sementara | – | – |
| API-PAY-001 | `POST /payments` | Intent transfer untuk invoice | customer | invoice_numbers:refs,bank_account_code:code | – | PaymentInstruction | INVOICE_NOT_PAYABLE,INVALID_STATE_TRANSITION | Validasi kepemilikan dan buat alokasi pending | PaymentIntentCreated | K |
| API-PAY-002 | `GET /payments` | Histori pembayaran | customer | – | Page,status?,order? | Page<Payment> | – | – | – | – |
| API-PAY-003 | `GET /payments/{number}` | Rincian pembayaran | customer | – | – | PaymentInstruction | NOT_FOUND | – | – | – |
| API-PAY-004 | `POST /payments/{number}/proofs` | Kirim proof READY | customer | file_id:ulid | – | Payment | FILE_NOT_READY,INVALID_STATE_TRANSITION | Tambah proof dan perpanjang hold bila masih aktif | PaymentSubmitted | K |
| API-PAY-005 | `GET /bank-accounts` | Pilihan rekening transfer | customer | – | – | Bank[] | – | – | – | – |
| API-REQ-001 | `POST /request-orders` | Buat request | customer | title:text160,description:text5000,items:RequestItem[],budget_amount?:money,target_date?:date | – | RequestOrder | VALIDATION_FAILED | Buat request dan item; attachment lewat endpoint file | RequestOrderSubmitted | K |
| API-REQ-002 | `GET /request-orders` | Daftar request | customer | – | Page,status? | Page<RequestOrder> | – | – | – | – |
| API-REQ-003 | `GET /request-orders/{number}` | Detail request dan quotation | customer | – | – | RequestDetail | NOT_FOUND | – | – | – |
| API-REQ-004 | `POST /request-orders/{number}/messages` | Kirim pesan | customer | body:text5000 | – | Message | INVALID_STATE_TRANSITION | Tambah diskusi visibility CUSTOMER | RequestMessageAdded | K |
| API-REQ-005 | `POST /request-orders/{number}/cancellations` | Batalkan request belum converted | customer | reason_code:code | – | RequestOrder | INVALID_STATE_TRANSITION | Batalkan dan withdraw versi SENT | RequestOrderCancelled | K |
| API-REQ-006 | `POST /request-orders/{number}/quotations/{quotation_number}/decisions` | Accept, reject, atau minta revisi | customer | decision:ACCEPT/REJECT/REVISE,address_id?:ulid,note?:text1000 | – | QuotationDecision | QUOTE_EXPIRED,INVALID_STATE_TRANSITION | Accept freeze dan buat order+invoice sekali; alamat wajib untuk ACCEPT | QuotationAccepted/QuotationResponded,OrderCreated,InvoiceIssued | K |
| API-SHP-001 | `GET /shipments/{number}` | Detail dan tracking paket | customer | – | – | Shipment | NOT_FOUND | – | – | – |
| API-RFD-001 | `POST /refunds` | Ajukan refund | customer | RefundInput | – | Refund | REFUND_LIMIT,INVALID_STATE_TRANSITION | Buat case dan item untuk review | RefundRequested | K |
| API-RFD-002 | `GET /refunds` | Daftar refund | customer | – | Page,order?,status? | Page<Refund> | – | – | – | – |
| API-RFD-003 | `GET /refunds/{number}` | Rincian refund | customer | – | – | Refund | NOT_FOUND | – | – | – |
| API-RTN-001 | `POST /returns` | Ajukan return | customer | ReturnInput | – | ReturnCase | RETURN_WINDOW_CLOSED,QUANTITY_CONFLICT | Buat claim dan hold qty eligible; bukti mengikuti endpoint file | ReturnRequested | K |
| API-RTN-002 | `GET /returns` | Daftar return | customer | – | Page,order?,status? | Page<ReturnCase> | – | – | – | – |
| API-RTN-003 | `GET /returns/{number}` | Rincian return dan instruksi | customer | – | – | ReturnCase | NOT_FOUND | – | – | – |
| API-RTN-004 | `POST /returns/{number}/dispatches` | Laporkan resi retur | customer | tracking_number:text120 | – | ReturnCase | INVALID_STATE_TRANSITION | Simpan resi dan state IN_TRANSIT | ReturnDispatched | K |
| API-NTF-001 | `GET /notifications` | Inbox | customer | – | Cursor,unread? | CursorPage<Notification> | – | – | – | – |
| API-NTF-002 | `PUT /notifications/{id}/read-receipt` | Tandai telah dibaca | customer | – | – | Notification | NOT_FOUND | Set read_at sekali | – | – |
| API-FND-002 | `POST /files` | Unggah ke owner yang diizinkan | customer | FileUpload | – | File | UPLOAD_REJECTED,NOT_FOUND | Quarantine dan scan async | FileUploaded | K |
| API-FND-003 | `GET /files/{id}` | Status file milik akun | customer | – | – | File | NOT_FOUND | – | – | – |
| API-FND-004 | `GET /files/{id}/download` | URL sementara | customer | – | – | Download | FILE_NOT_READY,NOT_FOUND | Audit unduhan sensitif | – | – |
| API-PRE-008 | `GET /preorders/{slug}/allocations` | Alokasi PO akun pada kampanye | customer | – | Page | Page<Allocation> | NOT_FOUND | – | – | – |
| API-PAY-019 | `POST /payments/{number}/cancellations` | Batalkan intent belum ada bukti | customer | – | – | Payment | INVALID_STATE_TRANSITION | Batalkan intent UNPAID tanpa menambah waktu hold | PaymentIntentCancelled | K |
| API-AUTH-018 | `POST /admin/auth/step-up` | Konfirmasi ulang staff sebelum aksi sensitif | admin-auth | password:password,code:otp | – | AuthAck | INVALID_CHALLENGE,THROTTLED | Set timestamp step-up pada sesi staff yang aktif | StaffReauthenticated | – |

## Admin API

| ID | Method dan path | Tujuan | Auth / permission | Body | Query | Respons | Error khusus | Efek | Event | Idempotensi |
|---|---|---|---|---|---|---|---|---|---|---|
| API-AUTH-014 | `POST /admin/auth/login` | Mulai sign-in staff | admin-auth | email:email,password:password | – | MfaChallenge | BAD_CREDENTIALS,THROTTLED | Buat sesi pre-auth dan challenge | – | – |
| API-AUTH-015 | `POST /admin/auth/mfa-challenges` | Selesaikan staff MFA | admin-auth | code:otp | – | Me | INVALID_CHALLENGE,THROTTLED | Promote sesi setelah MFA dan rotate ID | StaffSignedIn | – |
| API-AUTH-016 | `POST /admin/auth/mfa-enrollments` | Mulai enrollment dari undangan | admin-auth | token:token,password:password | – | Enrollment | INVALID_CHALLENGE | Buat encrypted seed pending, tampilkan sekali | – | – |
| API-AUTH-017 | `POST /admin/auth/mfa-enrollment-verifications` | Konfirmasi enrollment | admin-auth | token:token,code:otp | – | AuthAck | INVALID_CHALLENGE | Tandai confirmed dan konsumsi token | StaffMfaEnrolled | – |
| API-CAT-004 | `GET /admin/products` | Daftar products | catalog.product.view | – | Page,q?,active? | Page<ProductDetail> | VALIDATION_FAILED | – | – | – |
| API-CAT-005 | `GET /admin/products/{id}` | Detail products | catalog.product.view | – | – | ProductDetail | NOT_FOUND | – | – | – |
| API-CAT-006 | `POST /admin/products` | Buat products | catalog.product.manage | ProductInput | – | ProductDetail | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | ProductChanged | K |
| API-CAT-007 | `PATCH /admin/products/{id}` | Edit atau arsip products | catalog.product.manage | Partial<ProductInput> | – | ProductDetail | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | ProductChanged | K |
| API-CAT-008 | `GET /admin/variants` | Daftar variants | catalog.variant.view | – | Page,q?,active? | Page<Variant> | VALIDATION_FAILED | – | – | – |
| API-CAT-009 | `GET /admin/variants/{id}` | Detail variants | catalog.variant.view | – | – | Variant | NOT_FOUND | – | – | – |
| API-CAT-010 | `POST /admin/variants` | Buat variants | catalog.variant.manage | VariantInput | – | Variant | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | VariantChanged | K |
| API-CAT-011 | `PATCH /admin/variants/{id}` | Edit atau arsip variants | catalog.variant.manage | Partial<VariantInput> | – | Variant | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | VariantChanged | K |
| API-CAT-012 | `GET /admin/categories` | Daftar categories | catalog.category.view | – | Page,q?,active? | Page<Category> | VALIDATION_FAILED | – | – | – |
| API-CAT-013 | `GET /admin/categories/{id}` | Detail categories | catalog.category.view | – | – | Category | NOT_FOUND | – | – | – |
| API-CAT-014 | `POST /admin/categories` | Buat categories | catalog.category.manage | CategoryInput | – | Category | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | CategoryChanged | K |
| API-CAT-015 | `PATCH /admin/categories/{id}` | Edit atau arsip categories | catalog.category.manage | Partial<CategoryInput> | – | Category | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | CategoryChanged | K |
| API-CAT-016 | `GET /admin/brands` | Daftar brands | catalog.brand.view | – | Page,q?,active? | Page<Dictionary> | VALIDATION_FAILED | – | – | – |
| API-CAT-017 | `GET /admin/brands/{id}` | Detail brands | catalog.brand.view | – | – | Dictionary | NOT_FOUND | – | – | – |
| API-CAT-018 | `POST /admin/brands` | Buat brands | catalog.brand.manage | MasterInput | – | Dictionary | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | BrandChanged | K |
| API-CAT-019 | `PATCH /admin/brands/{id}` | Edit atau arsip brands | catalog.brand.manage | Partial<MasterInput> | – | Dictionary | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | BrandChanged | K |
| API-CAT-020 | `GET /admin/collections` | Daftar collections | catalog.collection.view | – | Page,q?,active? | Page<Dictionary> | VALIDATION_FAILED | – | – | – |
| API-CAT-021 | `GET /admin/collections/{id}` | Detail collections | catalog.collection.view | – | – | Dictionary | NOT_FOUND | – | – | – |
| API-CAT-022 | `POST /admin/collections` | Buat collections | catalog.collection.manage | MasterInput | – | Dictionary | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | CollectionChanged | K |
| API-CAT-023 | `PATCH /admin/collections/{id}` | Edit atau arsip collections | catalog.collection.manage | Partial<MasterInput> | – | Dictionary | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | CollectionChanged | K |
| API-CAT-024 | `GET /admin/tags` | Daftar tags | catalog.tag.view | – | Page,q?,active? | Page<Dictionary> | VALIDATION_FAILED | – | – | – |
| API-CAT-025 | `GET /admin/tags/{id}` | Detail tags | catalog.tag.view | – | – | Dictionary | NOT_FOUND | – | – | – |
| API-CAT-026 | `POST /admin/tags` | Buat tags | catalog.tag.manage | MasterInput | – | Dictionary | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | TagChanged | K |
| API-CAT-027 | `PATCH /admin/tags/{id}` | Edit atau arsip tags | catalog.tag.manage | Partial<MasterInput> | – | Dictionary | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | TagChanged | K |
| API-CAT-028 | `GET /admin/attributes` | Daftar attributes | catalog.attribute.view | – | Page,q?,active? | Page<Dictionary> | VALIDATION_FAILED | – | – | – |
| API-CAT-029 | `GET /admin/attributes/{id}` | Detail attributes | catalog.attribute.view | – | – | Dictionary | NOT_FOUND | – | – | – |
| API-CAT-030 | `POST /admin/attributes` | Buat attributes | catalog.attribute.manage | MasterInput | – | Dictionary | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | AttributeChanged | K |
| API-CAT-031 | `PATCH /admin/attributes/{id}` | Edit atau arsip attributes | catalog.attribute.manage | Partial<MasterInput> | – | Dictionary | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | AttributeChanged | K |
| API-CAT-032 | `GET /admin/attribute-values` | Daftar attribute-values | catalog.attribute.view | – | Page,q?,active? | Page<AttributeValue> | VALIDATION_FAILED | – | – | – |
| API-CAT-033 | `GET /admin/attribute-values/{id}` | Detail attribute-values | catalog.attribute.view | – | – | AttributeValue | NOT_FOUND | – | – | – |
| API-CAT-034 | `POST /admin/attribute-values` | Buat attribute-values | catalog.attribute.manage | AttributeValueInput | – | AttributeValue | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | AttributeValueChanged | K |
| API-CAT-035 | `PATCH /admin/attribute-values/{id}` | Edit atau arsip attribute-values | catalog.attribute.manage | Partial<AttributeValueInput> | – | AttributeValue | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | AttributeValueChanged | K |
| API-CAT-036 | `GET /admin/product-media` | Daftar product-media | catalog.media.view | – | Page,q?,active? | Page<Media> | VALIDATION_FAILED | – | – | – |
| API-CAT-037 | `GET /admin/product-media/{id}` | Detail product-media | catalog.media.view | – | – | Media | NOT_FOUND | – | – | – |
| API-CAT-038 | `POST /admin/product-media` | Buat product-media | catalog.media.manage | MediaInput | – | Media | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | ProductMediaChanged | K |
| API-CAT-039 | `PATCH /admin/product-media/{id}` | Edit atau arsip product-media | catalog.media.manage | Partial<MediaInput> | – | Media | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | ProductMediaChanged | K |
| API-INV-001 | `GET /admin/warehouses` | Daftar warehouses | inventory.warehouse.view | – | Page,q?,active? | Page<Warehouse> | VALIDATION_FAILED | – | – | – |
| API-INV-002 | `GET /admin/warehouses/{id}` | Detail warehouses | inventory.warehouse.view | – | – | Warehouse | NOT_FOUND | – | – | – |
| API-INV-003 | `POST /admin/warehouses` | Buat warehouses | inventory.warehouse.manage | WarehouseInput | – | Warehouse | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | WarehouseChanged | K |
| API-INV-004 | `PATCH /admin/warehouses/{id}` | Edit atau arsip warehouses | inventory.warehouse.manage | Partial<WarehouseInput> | – | Warehouse | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | WarehouseChanged | K |
| API-GEO-002 | `GET /admin/regions` | Daftar regions | geography.region.view | – | Page,q?,active? | Page<Region> | VALIDATION_FAILED | – | – | – |
| API-GEO-003 | `GET /admin/regions/{id}` | Detail regions | geography.region.view | – | – | Region | NOT_FOUND | – | – | – |
| API-GEO-004 | `POST /admin/regions` | Buat regions | geography.region.manage | RegionInput | – | Region | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | RegionChanged | K |
| API-GEO-005 | `PATCH /admin/regions/{id}` | Edit atau arsip regions | geography.region.manage | Partial<RegionInput> | – | Region | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | RegionChanged | K |
| API-GEO-006 | `GET /admin/reason-codes` | Daftar reason-codes | geography.reason.view | – | Page,q?,active? | Page<Reason> | VALIDATION_FAILED | – | – | – |
| API-GEO-007 | `GET /admin/reason-codes/{id}` | Detail reason-codes | geography.reason.view | – | – | Reason | NOT_FOUND | – | – | – |
| API-GEO-008 | `POST /admin/reason-codes` | Buat reason-codes | geography.reason.manage | ReasonInput | – | Reason | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | ReasonChanged | K |
| API-GEO-009 | `PATCH /admin/reason-codes/{id}` | Edit atau arsip reason-codes | geography.reason.manage | Partial<ReasonInput> | – | Reason | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | ReasonChanged | K |
| API-GEO-010 | `GET /admin/units` | Daftar units | geography.unit.view | – | Page,q?,active? | Page<Dictionary> | VALIDATION_FAILED | – | – | – |
| API-GEO-011 | `GET /admin/units/{id}` | Detail units | geography.unit.view | – | – | Dictionary | NOT_FOUND | – | – | – |
| API-GEO-012 | `POST /admin/units` | Buat units | geography.unit.manage | MasterInput | – | Dictionary | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | UnitChanged | K |
| API-GEO-013 | `PATCH /admin/units/{id}` | Edit atau arsip units | geography.unit.manage | Partial<MasterInput> | – | Dictionary | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | UnitChanged | K |
| API-GEO-014 | `GET /admin/tax-classes` | Daftar tax-classes | geography.tax.view | – | Page,q?,active? | Page<TaxClass> | VALIDATION_FAILED | – | – | – |
| API-GEO-015 | `GET /admin/tax-classes/{id}` | Detail tax-classes | geography.tax.view | – | – | TaxClass | NOT_FOUND | – | – | – |
| API-GEO-016 | `POST /admin/tax-classes` | Buat tax-classes | geography.tax.manage | TaxInput | – | TaxClass | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | TaxClassChanged | K |
| API-GEO-017 | `PATCH /admin/tax-classes/{id}` | Edit atau arsip tax-classes | geography.tax.manage | Partial<TaxInput> | – | TaxClass | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | TaxClassChanged | K |
| API-SHP-003 | `GET /admin/couriers` | Daftar couriers | shipping.courier.view | – | Page,q?,active? | Page<Dictionary> | VALIDATION_FAILED | – | – | – |
| API-SHP-004 | `GET /admin/couriers/{id}` | Detail couriers | shipping.courier.view | – | – | Dictionary | NOT_FOUND | – | – | – |
| API-SHP-005 | `POST /admin/couriers` | Buat couriers | shipping.courier.manage | MasterInput | – | Dictionary | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | CourierChanged | K |
| API-SHP-006 | `PATCH /admin/couriers/{id}` | Edit atau arsip couriers | shipping.courier.manage | Partial<MasterInput> | – | Dictionary | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | CourierChanged | K |
| API-SHP-007 | `GET /admin/courier-services` | Daftar courier-services | shipping.service.view | – | Page,q?,active? | Page<CourierService> | VALIDATION_FAILED | – | – | – |
| API-SHP-008 | `GET /admin/courier-services/{id}` | Detail courier-services | shipping.service.view | – | – | CourierService | NOT_FOUND | – | – | – |
| API-SHP-009 | `POST /admin/courier-services` | Buat courier-services | shipping.service.manage | ServiceInput | – | CourierService | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | CourierServiceChanged | K |
| API-SHP-010 | `PATCH /admin/courier-services/{id}` | Edit atau arsip courier-services | shipping.service.manage | Partial<ServiceInput> | – | CourierService | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | CourierServiceChanged | K |
| API-SHP-011 | `GET /admin/shipping-rates` | Daftar shipping-rates | shipping.rate.view | – | Page,q?,active? | Page<ShippingRate> | VALIDATION_FAILED | – | – | – |
| API-SHP-012 | `GET /admin/shipping-rates/{id}` | Detail shipping-rates | shipping.rate.view | – | – | ShippingRate | NOT_FOUND | – | – | – |
| API-SHP-013 | `POST /admin/shipping-rates` | Buat shipping-rates | shipping.rate.manage | RateInput | – | ShippingRate | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | ShippingRateChanged | K |
| API-SHP-014 | `PATCH /admin/shipping-rates/{id}` | Edit atau arsip shipping-rates | shipping.rate.manage | Partial<RateInput> | – | ShippingRate | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | ShippingRateChanged | K |
| API-PAY-007 | `GET /admin/bank-accounts` | Daftar bank-accounts | payments.bank.view | – | Page,q?,active? | Page<Bank> | VALIDATION_FAILED | – | – | – |
| API-PAY-008 | `GET /admin/bank-accounts/{id}` | Detail bank-accounts | payments.bank.view | – | – | Bank | NOT_FOUND | – | – | – |
| API-PAY-009 | `POST /admin/bank-accounts` | Buat bank-accounts | payments.bank.manage | BankInput | – | Bank | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | BankAccountChanged | K |
| API-PAY-010 | `PATCH /admin/bank-accounts/{id}` | Edit atau arsip bank-accounts | payments.bank.manage | Partial<BankInput> | – | Bank | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | BankAccountChanged | K |
| API-CNT-002 | `GET /admin/content` | Daftar content | content.entry.view | – | Page,q?,active? | Page<Content> | VALIDATION_FAILED | – | – | – |
| API-CNT-003 | `GET /admin/content/{id}` | Detail content | content.entry.view | – | – | Content | NOT_FOUND | – | – | – |
| API-CNT-004 | `POST /admin/content` | Buat content | content.entry.manage | ContentInput | – | Content | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | ContentChanged | K |
| API-CNT-005 | `PATCH /admin/content/{id}` | Edit atau arsip content | content.entry.manage | Partial<ContentInput> | – | Content | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | ContentChanged | K |
| API-PRM-001 | `GET /admin/promotions` | Daftar promotions | promotions.promotion.view | – | Page,q?,active? | Page<Promotion> | VALIDATION_FAILED | – | – | – |
| API-PRM-002 | `GET /admin/promotions/{id}` | Detail promotions | promotions.promotion.view | – | – | Promotion | NOT_FOUND | – | – | – |
| API-PRM-003 | `POST /admin/promotions` | Buat promotions | promotions.promotion.manage | PromotionInput | – | Promotion | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | PromotionChanged | K |
| API-PRM-004 | `PATCH /admin/promotions/{id}` | Edit atau arsip promotions | promotions.promotion.manage | Partial<PromotionInput> | – | Promotion | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | PromotionChanged | K |
| API-PRM-005 | `GET /admin/coupons` | Daftar coupons | promotions.coupon.view | – | Page,q?,active? | Page<Coupon> | VALIDATION_FAILED | – | – | – |
| API-PRM-006 | `GET /admin/coupons/{id}` | Detail coupons | promotions.coupon.view | – | – | Coupon | NOT_FOUND | – | – | – |
| API-PRM-007 | `POST /admin/coupons` | Buat coupons | promotions.coupon.manage | CouponInput | – | Coupon | VALIDATION_FAILED,DUPLICATE_RESOURCE | Simpan resource + audit | CouponChanged | K |
| API-PRM-008 | `PATCH /admin/coupons/{id}` | Edit atau arsip coupons | promotions.coupon.manage | Partial<CouponInput> | – | Coupon | NOT_FOUND,VALIDATION_FAILED,DUPLICATE_RESOURCE,INVALID_STATE_TRANSITION | Ubah field allowlisted + audit | CouponChanged | K |
| API-INV-005 | `GET /admin/stock-levels` | Daftar stock-levels | inventory.stock.view | – | Page,warehouse?,sku?,low_stock? | Page<StockLevel> | VALIDATION_FAILED | – | – | – |
| API-INV-006 | `GET /admin/stock-levels/{id}` | Detail stock-levels | inventory.stock.view | – | – | StockLevel | NOT_FOUND | – | – | – |
| API-INV-007 | `GET /admin/stock-movements` | Daftar stock-movements | inventory.movement.view | – | Cursor,level_id?,type?,from?,to? | CursorPage<Movement> | VALIDATION_FAILED | – | – | – |
| API-INV-008 | `GET /admin/stock-movements/{id}` | Detail stock-movements | inventory.movement.view | – | – | Movement | NOT_FOUND | – | – | – |
| API-INV-009 | `GET /admin/stock-reservations` | Daftar stock-reservations | inventory.reservation.view | – | Page,order?,status?,expires_before? | Page<StockReservation> | VALIDATION_FAILED | – | – | – |
| API-INV-010 | `GET /admin/stock-reservations/{id}` | Detail stock-reservations | inventory.reservation.view | – | – | StockReservation | NOT_FOUND | – | – | – |
| API-INV-011 | `GET /admin/stock-adjustments` | Daftar stock-adjustments | inventory.adjustment.view | – | Page,status?,from?,to? | Page<Adjustment> | VALIDATION_FAILED | – | – | – |
| API-INV-012 | `GET /admin/stock-adjustments/{id}` | Detail stock-adjustments | inventory.adjustment.view | – | – | Adjustment | NOT_FOUND | – | – | – |
| API-PRE-003 | `GET /admin/preorder-campaigns` | Daftar preorder-campaigns | preorders.campaign.view | – | Page,status?,from?,to? | Page<CampaignDetail> | VALIDATION_FAILED | – | – | – |
| API-PRE-004 | `GET /admin/preorder-campaigns/{number}` | Detail preorder-campaigns | preorders.campaign.view | – | – | CampaignDetail | NOT_FOUND | – | – | – |
| API-PRC-001 | `GET /admin/procurement-batches` | Daftar procurement-batches | procurement.batch.view | – | Page,status?,from?,to? | Page<Batch> | VALIDATION_FAILED | – | – | – |
| API-PRC-002 | `GET /admin/procurement-batches/{number}` | Detail procurement-batches | procurement.batch.view | – | – | Batch | NOT_FOUND | – | – | – |
| API-REQ-007 | `GET /admin/request-orders` | Daftar request-orders | request_orders.request.view | – | Page,status?,from?,to? | Page<RequestDetail> | VALIDATION_FAILED | – | – | – |
| API-REQ-008 | `GET /admin/request-orders/{number}` | Detail request-orders | request_orders.request.view | – | – | RequestDetail | NOT_FOUND | – | – | – |
| API-ORD-006 | `GET /admin/orders` | Daftar orders | orders.order.view | – | Page,status?,method?,from?,to? | Page<OrderDetail> | VALIDATION_FAILED | – | – | – |
| API-ORD-007 | `GET /admin/orders/{number}` | Detail orders | orders.order.view | – | – | OrderDetail | NOT_FOUND | – | – | – |
| API-IVC-004 | `GET /admin/invoices` | Daftar invoices | invoicing.invoice.view | – | Page,status?,from?,to? | Page<Invoice> | VALIDATION_FAILED | – | – | – |
| API-IVC-005 | `GET /admin/invoices/{number}` | Detail invoices | invoicing.invoice.view | – | – | Invoice | NOT_FOUND | – | – | – |
| API-PAY-011 | `GET /admin/payments` | Daftar payments | payments.payment.view | – | Page,status?,order?,from?,to? | Page<Payment> | VALIDATION_FAILED | – | – | – |
| API-PAY-012 | `GET /admin/payments/{number}` | Detail payments | payments.payment.view | – | – | Payment | NOT_FOUND | – | – | – |
| API-PAY-013 | `GET /admin/payment-reconciliations` | Daftar payment-reconciliations | payments.reconciliation.view | – | Page,status?,from?,to? | Page<Reconciliation> | VALIDATION_FAILED | – | – | – |
| API-PAY-014 | `GET /admin/payment-reconciliations/{id}` | Detail payment-reconciliations | payments.reconciliation.view | – | – | Reconciliation | NOT_FOUND | – | – | – |
| API-SHP-015 | `GET /admin/shipments` | Daftar shipments | shipping.shipment.view | – | Page,status?,from?,to? | Page<Shipment> | VALIDATION_FAILED | – | – | – |
| API-SHP-016 | `GET /admin/shipments/{number}` | Detail shipments | shipping.shipment.view | – | – | Shipment | NOT_FOUND | – | – | – |
| API-RFD-004 | `GET /admin/refunds` | Daftar refunds | refunds.refund.view | – | Page,status?,from?,to? | Page<Refund> | VALIDATION_FAILED | – | – | – |
| API-RFD-005 | `GET /admin/refunds/{number}` | Detail refunds | refunds.refund.view | – | – | Refund | NOT_FOUND | – | – | – |
| API-RTN-005 | `GET /admin/returns` | Daftar returns | returns.return.view | – | Page,status?,from?,to? | Page<ReturnCase> | VALIDATION_FAILED | – | – | – |
| API-RTN-006 | `GET /admin/returns/{number}` | Detail returns | returns.return.view | – | – | ReturnCase | NOT_FOUND | – | – | – |
| API-CUS-010 | `GET /admin/customers` | Daftar customers | customers.customer.view | – | Page,q? | Page<CustomerAdmin> | VALIDATION_FAILED | – | – | – |
| API-CUS-011 | `GET /admin/customers/{id}` | Detail customers | customers.customer.view | – | – | CustomerAdmin | NOT_FOUND | – | – | – |
| API-ADM-001 | `GET /admin/staff` | Daftar staff | admin.staff.view | – | Page,q? | Page<Staff> | VALIDATION_FAILED | – | – | – |
| API-ADM-002 | `GET /admin/staff/{id}` | Detail staff | admin.staff.view | – | – | Staff | NOT_FOUND | – | – | – |
| API-ADM-003 | `GET /admin/roles` | Daftar roles | admin.role.view | – | Page,q? | Page<Role> | VALIDATION_FAILED | – | – | – |
| API-ADM-004 | `GET /admin/roles/{id}` | Detail roles | admin.role.view | – | – | Role | NOT_FOUND | – | – | – |
| API-ADM-005 | `GET /admin/permissions` | Daftar permissions | admin.role.view | – | Page,q? | Page<Permission> | VALIDATION_FAILED | – | – | – |
| API-ADM-006 | `GET /admin/audit-logs` | Daftar audit-logs | admin.audit.view | – | Cursor,from?,to?,actor_id?,action?,subject_type? | CursorPage<Audit> | VALIDATION_FAILED | – | – | – |
| API-ADM-007 | `GET /admin/settings` | Daftar settings | admin.setting.view | – | Page,q? | Page<Setting> | VALIDATION_FAILED | – | – | – |
| API-ADM-008 | `GET /admin/settings/{key}` | Detail settings | admin.setting.view | – | – | Setting | NOT_FOUND | – | – | – |
| API-ADM-009 | `GET /admin/integrations` | Daftar integrations | admin.integration.view | – | Page,domain? | Page<Integration> | VALIDATION_FAILED | – | – | – |
| API-ADM-010 | `GET /admin/integrations/{key}` | Detail integrations | admin.integration.view | – | – | Integration | NOT_FOUND | – | – | – |
| API-RPT-001 | `GET /admin/dashboard` | Ringkasan metrik dan antrean | reporting.dashboard.view | – | from?,to?,method? | Dashboard | VALIDATION_FAILED,NOT_FOUND | – | – | – |
| API-RPT-002 | `GET /admin/transaction-reports` | Rekonsiliasi transaksi | reporting.transaction.view | – | Page,from,to,method? | Page<TransactionSummary> | VALIDATION_FAILED,NOT_FOUND | – | – | – |
| API-GEO-018 | `POST /admin/region-imports` | Impor wilayah tervalidasi | geography.region.manage | file_id:ulid | – | Ack | VALIDATION_FAILED,NOT_FOUND | Upsert per code dan rekam audit | RegionImported | K |
| API-INV-013 | `POST /admin/stock-receipts` | Penerimaan stok fisik | inventory.adjustment.create | StockReceiptInput | – | StockLevel | VALIDATION_FAILED,NOT_FOUND,QUANTITY_CONFLICT | Catat movement INITIAL/PURCHASE | StockReceived | K |
| API-INV-014 | `POST /admin/stock-adjustments` | Adjustment non-zero dan beralasan | inventory.adjustment.create | AdjustmentInput | – | Adjustment | VALIDATION_FAILED,NOT_FOUND,QUANTITY_CONFLICT | Lock level, catat saldo dan audit | StockAdjusted | K |
| API-INV-015 | `POST /admin/stock-transfers` | Transfer stok Phase 2 | inventory.transfer.manage | source_level_id:int,target_level_id:int,qty:qty,reason_code:code | – | Ack | VALIDATION_FAILED,NOT_FOUND,QUANTITY_CONFLICT | Lock kedua level lalu mutasi dua movement | StockTransferred | K |
| API-PRE-005 | `POST /admin/preorder-campaigns` | Buat draft kampanye dan penawaran awal | preorders.campaign.manage | CampaignInput | – | CampaignDetail | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTA_FULL | Buat campaign dan variant quota | PreorderCreated | K |
| API-PRE-006 | `PATCH /admin/preorder-campaigns/{number}` | Perbarui field kampanye, ETA dan tenggat | preorders.campaign.manage | Partial<CampaignInput> | – | CampaignDetail | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTA_FULL | Lock kampanye dan quota, audit dan notify bila jadwal berubah | PreorderEtaUpdated | K |
| API-PRE-007 | `POST /admin/preorder-campaigns/{number}/actions/{action}` | Aksi kampanye terdaftar | preorders.campaign.manage | reason_code?:code,note?:text1000 | – | CampaignDetail | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTA_FULL | SCHEDULE,OPEN,CLOSE,START_PROCUREMENT,FULFILL,COMPLETE,CANCEL | PreorderStatusChanged | K |
| API-PRC-003 | `POST /admin/procurement-batches` | Buat batch PO atau RO | procurement.batch.manage | BatchInput | – | Batch | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT | Lock order items dan assign qty | ProcurementBatchCreated | K |
| API-PRC-004 | `POST /admin/procurement-batches/{number}/stage-events` | Catat milestone pengadaan | procurement.batch.manage | stage:ProcurementStage,note?:text1000,eta_date?:date | – | Batch | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT | Jalankan transisi | ProcurementStageChanged | K |
| API-PRC-005 | `POST /admin/procurement-batches/{number}/receipts` | Catat actual receipt dan shortage | procurement.batch.manage | items:ReceiptItem[],reason_code?:code,note?:text1000 | – | Batch | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT | Update received/failed, hasilkan review refund bila diperlukan | ProcurementReceiptRecorded | K |
| API-PRC-006 | `POST /admin/procurement-batches/{number}/closures` | Tutup batch resolved | procurement.batch.manage | reason_code?:code | – | Batch | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT | Close/cancel batch sesuai status dan histori item | ProcurementBatchClosed | K |
| API-REQ-009 | `POST /admin/request-orders/{number}/reviews` | Review/assign request | request_orders.request.review | decision:START/NEED_INFORMATION/REJECT/REOPEN,assignee_id?:int,reason_code?:code,note?:text1000 | – | RequestDetail | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTE_EXPIRED | Transisi request via review | RequestOrderReviewed | K |
| API-REQ-010 | `POST /admin/request-orders/{number}/messages` | Tambah diskusi atau catatan internal | request_orders.request.review | visibility:CUSTOMER/STAFF,body:text5000 | – | Message | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTE_EXPIRED | Simpan scope pesan | RequestMessageAdded | K |
| API-REQ-011 | `POST /admin/request-orders/{number}/quotations` | Buat draft versi baru | request_orders.quotation.manage | QuotationInput | – | Quotation | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTE_EXPIRED | Validasi qty, term dan nominal | QuotationDrafted | K |
| API-REQ-012 | `PATCH /admin/request-orders/{number}/quotations/{quotation_number}` | Edit draft | request_orders.quotation.manage | Partial<QuotationInput> | – | Quotation | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTE_EXPIRED | Tolak selain DRAFT | QuotationUpdated | K |
| API-REQ-013 | `POST /admin/request-orders/{number}/quotations/{quotation_number}/dispatches` | Kirim versi aktif | request_orders.quotation.manage | – | – | Quotation | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTE_EXPIRED | Freeze dan supersede versi SENT lama | QuotationSent | K |
| API-REQ-014 | `POST /admin/request-orders/{number}/quotations/{quotation_number}/withdrawals` | Tarik penawaran | request_orders.quotation.manage | reason_code:code | – | Quotation | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTE_EXPIRED | Tutup versi dan kembalikan request untuk review | QuotationWithdrawn | K |
| API-ORD-008 | `POST /admin/orders/{number}/actions/{action}` | Pembatalan dan completion order | orders.order.manage | reason_code?:code,note?:text1000 | – | OrderDetail | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTE_CHANGED | Allowlist CANCEL,COMPLETE dan policy terkait | OrderStatusChanged | K |
| API-ORD-009 | `POST /admin/orders/{number}/charge-consents` | Ajukan biaya PO/RO | orders.order.manage | ChargeInput | – | Consent | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUOTE_CHANGED | Kunci revisi dan kirim usulan biaya | ChargeProposed | K |
| API-IVC-006 | `POST /admin/orders/{number}/invoices` | Terbitkan balance atau invoice revisi | invoicing.invoice.manage | purpose:BALANCE/FULL_PAYMENT/DEPOSIT/ADDITIONAL_CHARGE,replaces_invoice_number?:ref,due_at:datetime | – | Invoice | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FILE_NOT_READY | Server derivasi saldo atau revisi sumber yang disetujui | InvoiceIssued | K |
| API-IVC-007 | `POST /admin/invoices/{number}/voids` | Void tagihan eligible | invoicing.invoice.manage | reason_code:code | – | Invoice | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FILE_NOT_READY | Tolak jika ada applied allocation | InvoiceVoided | K |
| API-IVC-008 | `GET /admin/invoices/{number}/pdf` | Unduh dokumen staff | invoicing.invoice.view | – | – | Download | VALIDATION_FAILED,NOT_FOUND | Policy dan signed URL | – | – |
| API-IVC-009 | `POST /admin/invoices/{number}/pdf-requests` | Render ulang setelah gagal | invoicing.invoice.manage | – | – | Ack | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FILE_NOT_READY | Queue job deterministic | InvoicePdfRequested | K |
| API-PAY-015 | `POST /admin/payments/{number}/review-claims` | Claim/release antrean finance | payments.payment.review | action:CLAIM/RELEASE | – | Payment | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,TRANSFER_CONFLICT,FILE_NOT_READY,OUT_OF_STOCK | Lease review | PaymentReviewClaimed/Released | K |
| API-PAY-016 | `POST /admin/payments/{number}/decisions` | Approve atau reject | payments.payment.verify | PaymentDecision | – | Payment | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,TRANSFER_CONFLICT,FILE_NOT_READY,OUT_OF_STOCK | Lock order/invoice, cek bukti dan mutasi, settle atau reject | PaymentApproved/PaymentRejected,OrderPaid | K |
| API-PAY-017 | `POST /admin/payments/{number}/reconciliations` | Catat dana pengecualian | payments.payment.recover | ReconciliationInput | – | Reconciliation | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,TRANSFER_CONFLICT,FILE_NOT_READY,OUT_OF_STOCK | Catat receipt bank dan alasan | PaymentReconciliationOpened | K |
| API-PAY-018 | `POST /admin/payment-reconciliations/{id}/decisions` | Apply atau catat return transfer pengecualian | payments.payment.recover | ReconciliationDecision | – | Reconciliation | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,TRANSFER_CONFLICT,FILE_NOT_READY,OUT_OF_STOCK | Kunci receipt yang sudah APPROVED dan availability sebelum apply/return | PaymentRecovered/ReconciliationReturned | K |
| API-SHP-017 | `POST /admin/shipments` | Rencanakan paket tanpa dispatch | shipping.shipment.manage | ShipmentInput | – | Shipment | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,DEPENDENCY_UNAVAILABLE | Claim qty dan snapshot layanan. Cek tagihan kembali saat dispatch | ShipmentCreated | K |
| API-SHP-018 | `POST /admin/shipments/{number}/dispatches` | Input resi dan serahkan paket | shipping.shipment.manage | service_code:code,tracking_number:text120 | – | Shipment | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,DEPENDENCY_UNAVAILABLE | Catat picked_up dan SALE RTS sekali | ShipmentStatusUpdated | K |
| API-SHP-019 | `POST /admin/shipments/{number}/tracking-events` | Input tracking manual berizin | shipping.tracking.manage | status:IN_TRANSIT/OUT_FOR_DELIVERY/DELIVERED/FAILED/RETURNED,occurred_at:datetime,note?:text1000 | – | Shipment | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,DEPENDENCY_UNAVAILABLE | Append event dari state yang diizinkan | ShipmentStatusUpdated | K |
| API-SHP-020 | `POST /admin/shipments/{number}/sync-requests` | Sinkronkan provider aktif Phase 2 | shipping.tracking.manage | – | – | Ack | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,DEPENDENCY_UNAVAILABLE | Enqueue sync dan ledger retry | ShippingSyncRequested | K |
| API-SHP-021 | `POST /admin/shipments/{number}/cancellations` | Batalkan paket belum serah | shipping.shipment.manage | reason_code:code | – | Shipment | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,DEPENDENCY_UNAVAILABLE | Lepas claim paket | ShipmentCancelled | K |
| API-RFD-006 | `POST /admin/refunds/{number}/reviews` | Review kasus dan nominal | refunds.refund.review | RefundReview | – | Refund | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,REFUND_LIMIT,TRANSFER_CONFLICT,FILE_NOT_READY | Kunci cap dan kuantitas, audit | RefundApproved/RefundRejected | K |
| API-RFD-007 | `POST /admin/refunds/{number}/execution-attempts` | Mulai atau catat attempt gagal | refunds.refund.execute | action:START/FAIL/RETRY,reason_code?:code | – | Refund | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,REFUND_LIMIT,TRANSFER_CONFLICT,FILE_NOT_READY | Tetap pegang cap sampai hasil bank pasti | RefundProcessing/RefundFailed | K |
| API-RFD-008 | `POST /admin/refunds/{number}/transfers` | Konfirmasi transfer aktual | refunds.refund.execute | RefundTransferInput | – | Refund | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,REFUND_LIMIT,TRANSFER_CONFLICT,FILE_NOT_READY | Catat proof dan alokasi, recompute pembayaran | RefundCompleted | K |
| API-RTN-007 | `POST /admin/returns/{number}/reviews` | Review atau instruksi retur | returns.return.review | ReturnReview | – | ReturnCase | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,FILE_NOT_READY | Set persetujuan/instruksi atau alasan penolakan | ReturnReviewed | K |
| API-RTN-008 | `POST /admin/returns/{number}/receipts` | Terima barang | returns.return.inspect | note?:text1000 | – | ReturnCase | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,FILE_NOT_READY | Set RECEIVED dan timestamp | ReturnReceived | K |
| API-RTN-009 | `POST /admin/returns/{number}/inspections` | Inspeksi dan disposition | returns.return.inspect | InspectionInput | – | ReturnCase | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,FILE_NOT_READY | Set hasil, catat return movement bila eligible | ReturnInspected | K |
| API-RTN-010 | `POST /admin/returns/{number}/resolutions` | Terapkan keputusan kasus | returns.return.review | resolution:REFUND/REPLACEMENT/REPAIR/SHIP_MISSING_ITEM/REJECTED,note:text1000 | – | ReturnCase | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,QUANTITY_CONFLICT,FILE_NOT_READY | Hubungkan refund atau shipment/resolusi kerja | ReturnResolved | K |
| API-ADM-011 | `POST /admin/staff` | Undang staff | admin.staff.manage | email:email,roles:codes | – | Staff | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FORBIDDEN,DUPLICATE_RESOURCE | Provision staff, token enrollment single-use dan audit | StaffInvited | K |
| API-ADM-012 | `PATCH /admin/staff/{id}` | Aktif/nonaktif dan assign role | admin.staff.manage | active?:bool,roles?:codes | – | Staff | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FORBIDDEN,DUPLICATE_RESOURCE | Cegah kehilangan admin terakhir dan invalidate sesi bila nonaktif | StaffChanged | K |
| API-ADM-013 | `DELETE /admin/staff/{id}/sessions` | Revoke seluruh device staff | admin.staff.manage | – | – | Ack | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FORBIDDEN,DUPLICATE_RESOURCE | Cabut sesi setelah audit | StaffSessionsRevoked | K |
| API-ADM-014 | `POST /admin/roles` | Buat role | admin.role.manage | name:code,permissions:codes | – | Role | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FORBIDDEN,DUPLICATE_RESOURCE | Validasi katalog | RoleCreated | K |
| API-ADM-015 | `PATCH /admin/roles/{id}` | Ganti permission role | admin.role.manage | permissions:codes | – | Role | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FORBIDDEN,DUPLICATE_RESOURCE | Lock katalog, audit dan invalidate cache | RoleChanged | K |
| API-ADM-016 | `PUT /admin/settings/{key}` | Update setting allowlisted | admin.setting.manage | value:SettingValue | – | Setting | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FORBIDDEN,DUPLICATE_RESOURCE | Validasi schema key, audit | SettingChanged | K |
| API-ADM-017 | `PUT /admin/integrations/{key}` | Atur provider dan secret reference | admin.integration.manage | enabled:bool,settings:IntegrationInput | – | Integration | VALIDATION_FAILED,NOT_FOUND,INVALID_STATE_TRANSITION,FORBIDDEN,DUPLICATE_RESOURCE | Validasi referensi/kemampuan provider, audit | IntegrationChanged | K |
| API-FND-007 | `POST /admin/files` | Unggah file untuk kasus admin | foundation.file.manage | FileUpload | – | File | VALIDATION_FAILED,NOT_FOUND,FILE_NOT_READY,UPLOAD_REJECTED | Quarantine, scan dan assign | FileUploaded | K |
| API-FND-008 | `GET /admin/files/{id}` | Status file dan metadata | foundation.file.view | – | – | File | VALIDATION_FAILED,NOT_FOUND | Policy parent resource | – | – |
| API-FND-009 | `GET /admin/files/{id}/download` | Signed URL file | foundation.file.view | – | – | Download | VALIDATION_FAILED,NOT_FOUND | Policy parent, audit jika sensitif | – | – |
| API-SHP-022 | `POST /admin/shipping-quotes` | Quote ongkir PO/RO | shipping.rate.view | order_number:ref,items:ShipmentItemInput[] | – | ShippingQuote[] | NO_SHIPPING_RATE,QUANTITY_CONFLICT | Snapshot opsi tarif untuk charge consent | – | K |
| API-IVC-010 | `POST /admin/orders/{number}/credit-adjustments` | Koreksi sisa tagihan setelah supplier shortage | invoicing.invoice.manage | invoice_number:ref,order_item_id:ulid,reason_code:code | – | Invoice | INVALID_STATE_TRANSITION,QUANTITY_CONFLICT | Void invoice belum dibayar dan terbitkan revisi qty eligible | InvoiceVoided,InvoiceIssued | K |
| API-CUS-012 | `GET /admin/customers/{id}/addresses` | Alamat customer untuk kebutuhan support | customers.customer.pii_view | – | Page | Page<Address> | NOT_FOUND | Audit akses PII | CustomerAddressAccessed | – |
| API-CUS-013 | `GET /admin/customers/{id}/activity` | Histori order dan aktivitas yang dapat dilihat staff | customers.customer.view | – | Cursor,from?,to? | CursorPage<CustomerActivity> | NOT_FOUND | Audit akses record support | CustomerActivityAccessed | – |
| API-PAY-020 | `POST /admin/payment-reconciliations/{id}/reviews` | Checker review dana exception | payments.reconciliation.review | decision:APPROVE/REJECT,reason_code?:code,note:text1000 | – | Reconciliation | INVALID_STATE_TRANSITION,FORBIDDEN | Set reviewed_by dari actor lain dan catat keputusan | ReconciliationReviewed | K |
| API-ORD-010 | `POST /admin/orders/{number}/packing-events` | Packing sebelum dispatch | orders.packing.manage | action:PACK_START/PACK_FINISH/READY_FOR_DISPATCH,shipment_number?:ref | – | OrderDetail | INVALID_STATE_TRANSITION,QUANTITY_CONFLICT | Catat readiness order awal atau paket tambahan | OrderStatusChanged | K |
| API-RFD-009 | `POST /admin/refunds/{number}/cancellations` | Tutup kasus tanpa transfer | refunds.refund.review | reason_code:code,note:text1000 | – | Refund | INVALID_STATE_TRANSITION,TRANSFER_CONFLICT | Pastikan transfer belum terjadi, tutup kasus dan lepas cap | RefundCancelled | K |
| API-IVC-011 | `POST /admin/invoices/{number}/due-date-revisions` | Terbitkan ulang tagihan overdue belum dibayar | invoicing.invoice.manage | due_at:datetime,reason_code:code | – | Invoice | INVALID_STATE_TRANSITION,TRANSFER_CONFLICT | Void dan issue invoice revisi serupa; initial order expired tetap recovery | InvoiceVoided,InvoiceIssued | K |

## Webhook API

| ID | Method dan path | Tujuan | Auth / permission | Body | Query | Respons | Error khusus | Efek | Event | Idempotensi |
|---|---|---|---|---|---|---|---|---|---|---|
| API-SHP-002 | `POST /webhooks/shipping/{provider}` | Provider tracking Phase 2 | webhook | RawProviderEvent | – | Ack | INVALID_SIGNATURE,INVALID_PROVIDER_EVENT | Verifikasi, simpan inbox sekali dan enqueue | ShippingWebhookReceived | – |
| API-PAY-006 | `POST /webhooks/payments/{provider}` | Provider payment Phase 2 | webhook | RawProviderEvent | – | Ack | INVALID_SIGNATURE,INVALID_PROVIDER_EVENT | Verifikasi, simpan inbox sekali dan enqueue | PaymentWebhookReceived | – |

## Internal / System API

| ID | Method dan path | Tujuan | Auth / permission | Body | Query | Respons | Error khusus | Efek | Event | Idempotensi |
|---|---|---|---|---|---|---|---|---|---|---|
| API-FND-005 | `GET /internal/readiness` | Probe jaringan internal | internal | – | – | Health | DEPENDENCY_UNAVAILABLE | – | – | – |
| API-FND-006 | `GET /internal/metrics` | Scrape monitoring | internal | – | – | Metrics | – | – | – | – |
