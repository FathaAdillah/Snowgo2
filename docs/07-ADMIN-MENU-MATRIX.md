# Admin menu dan RBAC matrix

Sidebar muncul saat staff memiliki permission baca untuk submenu. Button, route guard, dan API memeriksa permission aksi. Policy resource, state transition dan scope file berlaku setelah pemeriksaan permission. Kolom role memuat gabungan seluruh role yang memiliki setidaknya satu permission menu. Katalog grant di bawah menentukan aksi awal. Seluruh permission diberikan secara eksplisit kepada Super Admin, dan dapat ditugaskan ke role melalui role editor.

| Module | Menu | Submenu | Purpose | Role | Permission | Related API | Related tables | Priority | Phase |
|---|---|---|---|---|---|---|---|---|---|
| Reporting | Dashboard | Overview dan antrean | Penjualan, revenue, RTS/PO/RO, pending payment, shipping, refund, return, low stock | Super Admin, Admin, Product Manager, Inventory Staff, Order Staff, Finance, Customer Service, Warehouse, Content Manager | reporting.dashboard.view | API-RPT-001 | orders, payments, refunds, shipments, inventory_levels | P1 | MVP |
| Reporting | Finance | Transaction reports | Rekonsiliasi capture, invoice dan refund | Super Admin, Admin, Finance | reporting.transaction.view | API-RPT-002 | payments, refund_transactions, invoices | P1 | MVP |
| Orders | Commerce | Orders dan RTS | Cara beli, packing, cancellation, completion dan persetujuan biaya | Super Admin, Admin, Order Staff, Customer Service, Warehouse | orders.order.view, orders.order.manage, orders.packing.manage | API-ORD-006, API-ORD-007, API-ORD-008, API-ORD-009, API-ORD-010 | orders, order_items, order_addresses, status_histories, order_charge_consents, shipping_quotes, shipments | P0 | MVP |
| Preorders | Commerce | Kampanye PO, kuota dan ETA | Window, kapasitas, jadwal, allocation | Super Admin, Admin, Product Manager, Order Staff, Customer Service | preorders.campaign.view, preorders.campaign.manage | API-PRE-003, API-PRE-004, API-PRE-005, API-PRE-006, API-PRE-007 | preorder_campaigns, preorder_campaign_variants, preorder_allocations | P0 | MVP |
| Procurement | Commerce | Batch PO dan request | Pengadaan, ETA, receipt dan supplier failure | Super Admin, Admin, Order Staff, Warehouse | procurement.batch.view, procurement.batch.manage | API-PRC-001, API-PRC-002, API-PRC-003, API-PRC-004, API-PRC-005, API-PRC-006 | procurement_batches, procurement_batch_items, procurement_stage_events, order_items, preorder_allocations | P0 | MVP |
| RequestOrders | Commerce | Request dan quotations | Review diskusi, penawaran berversi, withdraw dan expiry | Super Admin, Admin, Order Staff, Customer Service | request_orders.request.view, request_orders.request.review, request_orders.quotation.manage | API-REQ-007, API-REQ-008, API-REQ-009, API-REQ-010, API-REQ-011, API-REQ-012, API-REQ-013, API-REQ-014 | request_orders, request_order_items, request_order_messages, quotations, status_histories, quotation_items | P0 | MVP |
| Invoicing | Commerce | Invoice, termin dan PDF | Issue, void, koreksi sisa dan ulang PDF | Super Admin, Admin, Order Staff, Finance, Customer Service | invoicing.invoice.view, invoicing.invoice.manage | API-IVC-004, API-IVC-005, API-IVC-006, API-IVC-007, API-IVC-008, API-IVC-009, API-IVC-010, API-IVC-011 | invoices, invoice_items, orders, payment_allocations, stored_files, order_items, payments | P0 | MVP |
| Payments | Finance | Pembayaran dan verifikasi | Cari intent, claim proof, approve atau reject | Super Admin, Admin, Finance, Customer Service | payments.payment.view, payments.payment.review, payments.payment.verify | API-PAY-011, API-PAY-012, API-PAY-015, API-PAY-016 | payments, payment_allocations, payment_proofs, invoices, orders, stock_reservations, preorder_allocations | P0 | MVP |
| Payments | Finance | Rekonsiliasi pengecualian | Late, duplicate, mismatch dan transfer balik | Super Admin, Admin, Finance | payments.reconciliation.view, payments.payment.recover, payments.reconciliation.review | API-PAY-013, API-PAY-014, API-PAY-017, API-PAY-018, API-PAY-020 | payment_reconciliations, payments, payment_allocations, inventory_levels, audit_logs | P0 | MVP |
| Payments | Finance | Rekening tujuan | Instruksi bank dan status aktif | Super Admin, Admin, Finance | payments.bank.view, payments.bank.manage | API-PAY-007, API-PAY-008, API-PAY-009, API-PAY-010 | bank_accounts | P0 | MVP |
| Shipping | Commerce | Paket dan dispatch | Buat paket, isi resi, dispatch atau cancel | Super Admin, Admin, Order Staff, Customer Service, Warehouse | shipping.shipment.view, shipping.shipment.manage | API-SHP-015, API-SHP-016, API-SHP-017, API-SHP-018, API-SHP-021 | shipments, shipment_items, tracking_events, order_items, inventory_movements | P0 | MVP |
| Shipping | Shipping | Tracking dan paket gagal | Filter FAILED, input event dan lihat histori | Super Admin, Admin, Order Staff, Customer Service, Warehouse | shipping.shipment.view, shipping.shipment.manage, shipping.tracking.manage | API-SHP-015, API-SHP-016, API-SHP-017, API-SHP-019 | shipments, shipment_items, tracking_events, order_items, orders | P0 | MVP |
| Shipping | Shipping | Kurir, jasa dan tarif | Multi-kurir dan quote domestik | Super Admin, Admin, Order Staff, Warehouse | shipping.courier.view, shipping.courier.manage, shipping.service.view, shipping.service.manage, shipping.rate.view, shipping.rate.manage | API-SHP-003, API-SHP-004, API-SHP-005, API-SHP-006, API-SHP-007, API-SHP-008, API-SHP-009, API-SHP-010, API-SHP-011, API-SHP-012, API-SHP-013, API-SHP-014, API-SHP-022 | couriers, courier_services, shipping_rates, shipping_quotes, orders, order_items | P0 | MVP |
| Shipping | Shipping | Sinkronisasi provider | Refresh tracking dengan audit | Super Admin, Admin, Order Staff, Warehouse | shipping.tracking.manage | API-SHP-020 | shipments, webhook_events | P2 | Phase 2 |
| Refunds | Finance | Refund dan bukti transfer | Review, cap, execute, proof dan histori transfer | Super Admin, Admin, Finance, Customer Service | refunds.refund.view, refunds.refund.review, refunds.refund.execute | API-RFD-004, API-RFD-005, API-RFD-006, API-RFD-007, API-RFD-008, API-RFD-009 | refunds, refund_items, refund_transactions, payment_allocations, orders, payments, audit_logs | P0 | MVP |
| Returns | Commerce | Return dan inspection | Review, instruksi, receipt, inspeksi dan resolusi | Super Admin, Admin, Customer Service, Warehouse | returns.return.view, returns.return.review, returns.return.inspect | API-RTN-005, API-RTN-006, API-RTN-007, API-RTN-008, API-RTN-009, API-RTN-010 | returns, return_items, inventory_movements, refunds, shipments | P0 | MVP |
| Catalog | Catalog | Produk | Master, publish, harga, media dan SEO | Super Admin, Admin, Product Manager, Content Manager | catalog.product.view, catalog.product.manage | API-CAT-004, API-CAT-005, API-CAT-006, API-CAT-007 | products, product_categories, product_tags, product_collections | P1 | MVP |
| Catalog | Catalog | Varian dan SKU | Harga, berat dan opsi | Super Admin, Admin, Product Manager, Content Manager | catalog.variant.view, catalog.variant.manage | API-CAT-008, API-CAT-009, API-CAT-010, API-CAT-011 | product_variants, variant_attribute_values | P1 | MVP |
| Catalog | Catalog | Kategori dan brand | Taksonomi produk | Super Admin, Admin, Product Manager, Content Manager | catalog.category.view, catalog.category.manage, catalog.brand.view, catalog.brand.manage | API-CAT-012, API-CAT-013, API-CAT-014, API-CAT-015, API-CAT-016, API-CAT-017, API-CAT-018, API-CAT-019 | categories, brands | P1 | MVP |
| Catalog | Catalog | Koleksi dan tags | Kurasi dan filter | Super Admin, Admin, Product Manager, Content Manager | catalog.collection.view, catalog.collection.manage, catalog.tag.view, catalog.tag.manage | API-CAT-020, API-CAT-021, API-CAT-022, API-CAT-023, API-CAT-024, API-CAT-025, API-CAT-026, API-CAT-027 | collections, tags | P1 | MVP |
| Catalog | Catalog | Atribut dan nilai | Opsi varian | Super Admin, Admin, Product Manager, Content Manager | catalog.attribute.view, catalog.attribute.manage | API-CAT-028, API-CAT-029, API-CAT-030, API-CAT-031, API-CAT-032, API-CAT-033, API-CAT-034, API-CAT-035 | attributes, attribute_values | P1 | MVP |
| Catalog | Catalog | Media | Gambar produk/varian, alt dan urutan | Super Admin, Admin, Product Manager, Content Manager | catalog.media.view, catalog.media.manage | API-CAT-036, API-CAT-037, API-CAT-038, API-CAT-039 | product_media, stored_files | P1 | MVP |
| Inventory | Inventory | Warehouses | Gudang, alamat dan default | Super Admin, Admin, Inventory Staff, Warehouse | inventory.warehouse.view, inventory.warehouse.manage | API-INV-001, API-INV-002, API-INV-003, API-INV-004 | warehouses | P0 | MVP |
| Inventory | Inventory | Stocks dan low stock | Saldo per gudang dan filter low_stock | Super Admin, Admin, Product Manager, Inventory Staff, Warehouse | inventory.stock.view | API-INV-005, API-INV-006 | inventory_levels | P0 | MVP |
| Inventory | Inventory | Movements | Ledger dan sumber transaksi | Super Admin, Admin, Inventory Staff, Warehouse | inventory.movement.view | API-INV-007, API-INV-008 | inventory_movements | P0 | MVP |
| Inventory | Inventory | Reservations | Hold aktif, committed, expired | Super Admin, Admin, Inventory Staff, Order Staff, Warehouse | inventory.reservation.view | API-INV-009, API-INV-010 | stock_reservations | P0 | MVP |
| Inventory | Inventory | Receipts dan adjustments | Terima barang, koreksi saldo dan alasan | Super Admin, Admin, Inventory Staff, Warehouse | inventory.adjustment.view, inventory.adjustment.create | API-INV-011, API-INV-012, API-INV-013, API-INV-014 | stock_adjustments, inventory_levels, inventory_movements | P0 | MVP |
| Inventory | Inventory | Transfers | Transfer antar gudang | Super Admin, Admin, Inventory Staff | inventory.transfer.manage | API-INV-015 | inventory_levels, inventory_movements, stock_transfers | P2 | Phase 2 |
| Customers | Customers | Customers dan profil | Akun, alamat, aktivitas dan ringkasan order | Super Admin, Admin, Order Staff, Customer Service | customers.customer.view, customers.customer.pii_view | API-CUS-010, API-CUS-011, API-CUS-012, API-CUS-013 | users, customer_profiles, addresses, audit_logs, orders, status_histories | P1 | MVP |
| Content | Content | Homepage, banner dan featured | Kurasi konten storefront | Super Admin, Admin, Content Manager | content.entry.view, content.entry.manage | API-CNT-002, API-CNT-003, API-CNT-004, API-CNT-005 | content_entries | P1 | MVP |
| Content | Content | Pages, FAQ dan kebijakan | Terms, privacy, return, refund dan PO | Super Admin, Admin, Content Manager | content.entry.view, content.entry.manage | API-CNT-002, API-CNT-003, API-CNT-004, API-CNT-005 | content_entries | P1 | MVP |
| Promotions | Marketing | Promo, coupon dan voucher | Eligibility, diskon dan kuota | Super Admin, Admin, Content Manager, Product Manager | promotions.promotion.view, promotions.promotion.manage, promotions.coupon.view, promotions.coupon.manage | API-PRM-001, API-PRM-002, API-PRM-003, API-PRM-004, API-PRM-005, API-PRM-006, API-PRM-007, API-PRM-008 | promotions, coupons, coupon_usages | P2 | Phase 2 |
| Geography | Master Data | Countries, provinces, cities, districts | Hierarki wilayah dan import | Super Admin, Admin, Product Manager, Order Staff | geography.region.view, geography.region.manage | API-GEO-002, API-GEO-003, API-GEO-004, API-GEO-005, API-GEO-018 | regions, stored_files | P1 | MVP |
| Geography | Master Data | Units, tax class dan reason codes | Data referensi lintas transaksi | Super Admin, Admin, Product Manager, Order Staff, Finance | geography.reason.view, geography.reason.manage, geography.unit.view, geography.unit.manage, geography.tax.view, geography.tax.manage | API-GEO-006, API-GEO-007, API-GEO-008, API-GEO-009, API-GEO-010, API-GEO-011, API-GEO-012, API-GEO-013, API-GEO-014, API-GEO-015, API-GEO-016, API-GEO-017 | reason_codes, units, tax_classes | P1 | MVP |
| Administration | Administration | Staff dan sessions | Undangan, assign role dan revoke device | Super Admin | admin.staff.view, admin.staff.manage | API-ADM-001, API-ADM-002, API-ADM-011, API-ADM-012, API-ADM-013, API-AUTH-018 | users, model_has_roles, sessions, staff_invitations | P0 | MVP |
| Administration | Administration | Roles dan permissions | Bundle permission dan katalog | Super Admin | admin.role.view, admin.role.manage | API-ADM-003, API-ADM-004, API-ADM-005, API-ADM-014, API-ADM-015 | roles, role_has_permissions, permissions | P0 | MVP |
| Administration | Administration | Audit logs | Filter actor, entity dan action | Super Admin, Admin, Finance | admin.audit.view | API-ADM-006 | audit_logs | P0 | MVP |
| Administration | Administration | Settings dan feature flags | Typed settings dengan audit | Super Admin, Admin | admin.setting.view, admin.setting.manage | API-ADM-007, API-ADM-008, API-ADM-016 | settings | P0 | MVP |
| Administration | Administration | Integrations dan credential references | Konfigurasi provider dan secret reference | Super Admin, Admin | admin.integration.view, admin.integration.manage | API-ADM-009, API-ADM-010, API-ADM-017 | integration_configs | P0 | MVP |
| Foundation | Administration | Bukti dan file | Scan dan signed URL berdasar parent resource | Super Admin, Admin, Product Manager, Order Staff, Finance, Customer Service, Warehouse, Content Manager | foundation.file.manage, foundation.file.view | API-FND-007, API-FND-008, API-FND-009 | stored_files | P0 | MVP |

## Grant awal

| Permission | Role selain Super Admin |
|---|---|
| admin.audit.view | Admin, Finance |
| admin.integration.manage | Admin |
| admin.integration.view | Admin |
| admin.role.manage | — |
| admin.role.view | — |
| admin.setting.manage | Admin |
| admin.setting.view | Admin |
| admin.staff.manage | — |
| admin.staff.view | — |
| catalog.attribute.manage | Admin, Product Manager |
| catalog.attribute.view | Admin, Product Manager, Content Manager |
| catalog.brand.manage | Admin, Product Manager |
| catalog.brand.view | Admin, Product Manager, Content Manager |
| catalog.category.manage | Admin, Product Manager |
| catalog.category.view | Admin, Product Manager, Content Manager |
| catalog.collection.manage | Admin, Product Manager |
| catalog.collection.view | Admin, Product Manager, Content Manager |
| catalog.media.manage | Admin, Product Manager |
| catalog.media.view | Admin, Product Manager, Content Manager |
| catalog.product.manage | Admin, Product Manager |
| catalog.product.view | Admin, Product Manager, Content Manager |
| catalog.tag.manage | Admin, Product Manager |
| catalog.tag.view | Admin, Product Manager, Content Manager |
| catalog.variant.manage | Admin, Product Manager |
| catalog.variant.view | Admin, Product Manager, Content Manager |
| content.entry.manage | Admin, Content Manager |
| content.entry.view | Admin, Content Manager |
| customers.customer.view | Admin, Order Staff, Customer Service |
| foundation.file.manage | Admin, Product Manager, Order Staff, Finance, Customer Service, Warehouse, Content Manager |
| foundation.file.view | Admin, Product Manager, Order Staff, Finance, Customer Service, Warehouse, Content Manager |
| geography.reason.manage | Admin |
| geography.reason.view | Admin, Product Manager, Order Staff, Finance |
| geography.region.manage | Admin |
| geography.region.view | Admin, Product Manager, Order Staff |
| geography.tax.manage | Admin |
| geography.tax.view | Admin, Product Manager, Order Staff, Finance |
| geography.unit.manage | Admin |
| geography.unit.view | Admin, Product Manager, Order Staff |
| inventory.adjustment.create | Admin, Inventory Staff, Warehouse |
| inventory.adjustment.view | Admin, Inventory Staff, Warehouse |
| inventory.movement.view | Admin, Inventory Staff, Warehouse |
| inventory.reservation.view | Admin, Inventory Staff, Order Staff, Warehouse |
| inventory.stock.view | Admin, Product Manager, Inventory Staff, Warehouse |
| inventory.transfer.manage | Admin, Inventory Staff |
| inventory.warehouse.manage | Admin, Inventory Staff |
| inventory.warehouse.view | Admin, Inventory Staff, Warehouse |
| invoicing.invoice.manage | Admin, Finance |
| invoicing.invoice.view | Admin, Order Staff, Finance, Customer Service |
| orders.order.manage | Admin, Order Staff |
| orders.order.view | Admin, Order Staff, Customer Service, Warehouse |
| payments.bank.manage | Admin, Finance |
| payments.bank.view | Admin, Finance |
| payments.payment.recover | Finance |
| payments.payment.review | Admin, Finance |
| payments.payment.verify | Admin, Finance |
| payments.payment.view | Admin, Finance, Customer Service |
| payments.reconciliation.view | Admin, Finance |
| preorders.campaign.manage | Admin, Order Staff |
| preorders.campaign.view | Admin, Product Manager, Order Staff, Customer Service |
| procurement.batch.manage | Admin, Order Staff |
| procurement.batch.view | Admin, Order Staff, Warehouse |
| promotions.coupon.manage | Admin, Product Manager, Content Manager |
| promotions.coupon.view | Admin, Content Manager |
| promotions.promotion.manage | Admin, Product Manager, Content Manager |
| promotions.promotion.view | Admin, Content Manager |
| refunds.refund.execute | Admin, Finance |
| refunds.refund.review | Admin, Finance |
| refunds.refund.view | Admin, Finance, Customer Service |
| reporting.dashboard.view | Admin, Product Manager, Inventory Staff, Order Staff, Finance, Customer Service, Warehouse, Content Manager |
| reporting.transaction.view | Admin, Finance |
| request_orders.quotation.manage | Admin, Order Staff |
| request_orders.request.review | Admin, Order Staff, Customer Service |
| request_orders.request.view | Admin, Order Staff, Customer Service |
| returns.return.inspect | Admin, Warehouse |
| returns.return.review | Admin, Customer Service |
| returns.return.view | Admin, Customer Service, Warehouse |
| shipping.courier.manage | Admin |
| shipping.courier.view | Admin, Order Staff, Warehouse |
| shipping.rate.manage | Admin |
| shipping.rate.view | Admin, Order Staff, Warehouse |
| shipping.service.manage | Admin |
| shipping.service.view | Admin, Order Staff, Warehouse |
| shipping.shipment.manage | Admin, Warehouse |
| shipping.shipment.view | Admin, Order Staff, Customer Service, Warehouse |
| shipping.tracking.manage | Admin, Order Staff, Warehouse |
| customers.customer.pii_view | Customer Service |
| payments.reconciliation.review | Finance |
| orders.packing.manage | Order Staff, Warehouse |

Maker-checker untuk refund dan recovery payment membedakan actor meskipun permission berasal dari satu role bundle. Perubahan yang mencabut kemampuan pengelolaan role/staff dari administrator aktif terakhir ditolak. Order status dan payment status mengikuti state machine deployment. Rekening, layanan shipping, tarif, reason codes dan wilayah adalah master.

System logs, Horizon dan metrics berada pada permukaan operator privat melalui IAM. Dokumen pembayaran, private attachment, detail alamat customer dan aktivitas sensitif mengikuti policy parent, scope dan masking. Menu Phase 2 disembunyikan sampai flag dan readiness aktif.

Khusus Compose development, menu profil menampilkan **Log Viewer** tepat di bawah **Pengaturan**. Tautan membuka `/log-viewerx` di host admin (termasuk port edge saat ini); autentikasi operator lokal tetap wajib untuk halaman dan API log. Menu, rute dan kredensial ini tidak dipakai di produksi.

[Lihat kontrak dan endpoint](05-API-CONTRACT.md) · [Keamanan](08-SECURITY.md)
