# ADR-003 — Reservasi stok

Status: Usulan untuk review dokumentasi.

## Context

Cart bisa ditinggal dan banyak checkout dapat memilih SKU yang sama.

## Options

Reserve saat cart; reserve saat checkout; kurangi stok fisik saat bayar.

## Trade-offs

Hold cart mengurangi availability tanpa komitmen; pengurangan hanya saat bayar memungkinkan beberapa pembeli telanjur transfer; reserve checkout membutuhkan TTL dan rekonsiliasi.

## Decision

Reserve saat checkout dengan lock PostgreSQL, TTL, commit setelah lunas, SALE saat dispatch. Kuota PO terpisah.

## Consequences

Expiry scheduler dan recovery finance harus aman saat bersaing. Pembayaran terlambat masuk antrean review.
