# ADR-002 — Arsitektur backend

Status: Usulan untuk review dokumentasi.

## Context

Tiga lifecycle berbagi transaksi finance/order dan perlu atomicity tanpa biaya operasional distribusi.

## Options

Laravel modular monolith; Laravel dengan folder teknis global; microservices.

## Trade-offs

Modul menambah kontrak internal dan kepemilikan eksplisit. Folder teknis global lebih mudah mulai namun coupling naik. Microservices menambah transaksi lintas jaringan dan deployment.

## Decision

Laravel modular monolith dengan batas modul, PostgreSQL dan outbox.

## Consequences

Orkestrator boleh memakai beberapa port sinkron di transaksi sama; event commit dipakai untuk pekerjaan async. Arch test menegakkan batas.
