# ADR-005 — Provider shipping

Status: Usulan untuk review dokumentasi.

## Context

Multi-kurir dan fallback operasional diperlukan ketika provider tidak tersedia.

## Options

Manual; aggregator tunggal hard-coded; port ShippingProvider dengan manual dan adapter aggregator/kurir.

## Trade-offs

Manual menambah kerja admin. Aggregator mempercepat tracking dan berbiaya eksternal. Port menambah normalisasi dan contract tests.

## Decision

Port shipping, kurir/tarif/resi manual MVP; adapter aggregator Phase 2 setelah kontrak, Biteship sebagai kandidat.

## Consequences

Polling dan failure handling dapat diuji dengan fake; admin mempunyai jalur manual dan histori provider.
