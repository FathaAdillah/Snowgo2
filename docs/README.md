# Dokumentasi Snowego E-Commerce

**Status** · Baseline rancangan untuk persetujuan. Development dimulai setelah review dokumentasi. [Risiko dan pertanyaan bisnis](01-BRD.md#asumsi-dan-pertanyaan-terbuka)

## Urutan baca

| Tahap | Dokumen |
|---|---|
| Referensi | [Analisis CrowdFunding](REFERENCE-ARCHITECTURE-ANALYSIS.md) · [Gap analysis](REQUIREMENT-GAP-ANALYSIS.md) |
| Kebutuhan | [01 BRD](01-BRD.md) · [02 PRD](02-PRD.md) |
| Solusi | [03 Arsitektur](03-TECH-STACK-ARCHITECTURE.md) · [04 Schema](04-DATABASE-SCHEMA.md) · [05 API contract](05-API-CONTRACT.md) · [Kamus tipe API](API-DATA-TYPES.md) |
| Operasi bisnis | [Panduan penggunaan: Ready Stock, Open Preorder dan Request Order](PANDUAN-OPERASIONAL-PEMBELIAN.md) · [06 Business flows](06-BUSINESS-FLOWS.md) · [07 Admin dan RBAC](07-ADMIN-MENU-MATRIX.md) · [Pola tabel admin](ADMIN-TABLES.md) |
| Quality dan operasi | [08 Security](08-SECURITY.md) · [09 Testing](09-TESTING-STRATEGY.md) · [10 Deployment](10-DEPLOYMENT.md) |
| Backlog dan review | [11 Task tracker](11-TASK-PROGRESS-TRACKER.md) · [Traceability](REQUIREMENTS-TRACEABILITY.md) · [Final consistency review](FINAL-CONSISTENCY-REVIEW.md) |

## Keputusan arsitektur

- [ADR-001 Framework frontend](adr/ADR-001-frontend-framework.md)
- [ADR-002 Modular monolith](adr/ADR-002-backend-architecture.md)
- [ADR-003 Reservasi inventory](adr/ADR-003-inventory-reservation.md)
- [ADR-004 Abstraksi payment](adr/ADR-004-payment-architecture.md)
- [ADR-005 Adapter shipping](adr/ADR-005-shipping-provider.md)

## Diagram

[System context](diagrams/system-context.md) · [Container](diagrams/container-diagram.md) · [Aliran teknis](diagrams/technical-flows.md) · [ERD](diagrams/erd.md)

[Ready to ship](diagrams/ready-to-ship-flow.md) · [Preorder](diagrams/preorder-flow.md) · [Request order](diagrams/request-order-flow.md) · [Payment](diagrams/payment-flow.md) · [Shipping](diagrams/shipping-flow.md) · [Refund dan return](diagrams/refund-return-flow.md) · [Inventory](diagrams/inventory-flow.md)

## Sumber kontrak dan perubahan

BRD menetapkan aturan bisnis dan assumption. PRD menetapkan acceptance dan lingkup release. Database, API, flow, dan RBAC masing-masing menjadi sumber skema, kontrak HTTP, transisi, dan grants. Ubah dokumen terkait serta traceability setiap perubahan domain. Status development dilacak di task tracker.

Mulai review dari Q-01–Q-05 di BRD. Setelah keputusan yang memengaruhi baseline disetujui, jalankan E01 Foundation dari task tracker. Gunakan test ID dan acceptance setiap task sebagai bukti selesai.
