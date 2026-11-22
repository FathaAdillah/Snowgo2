# ADR-004 — Pemrosesan pembayaran

Status: Usulan untuk review dokumentasi.

## Context

Transfer manual diperlukan sekarang dan gateway mungkin dibutuhkan nanti.

## Options

Transfer tertanam di order; payment domain dengan provider port; gateway wajib sejak awal.

## Trade-offs

Port menambah kontrak, memisahkan bukti dan settlement dari order. Gateway awal meningkatkan biaya dan ketergantungan.

## Decision

PaymentProvider dan RefundProvider dengan adapter manual terlebih dahulu, invoice dan allocation sebagai core.

## Consequences

Gateway memakai inbox event stabil dan adapter signature; settlement tetap melalui action core.
