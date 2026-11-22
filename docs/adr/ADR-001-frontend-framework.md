# ADR-001 — Framework frontend

Status: Usulan untuk review dokumentasi.

## Context

Permintaan Next.js bertabrakan dengan komponen Vue. Referensi memakai Vue/Vite untuk admin dan Next.js untuk web.

## Options

Nuxt 4 dua aplikasi; hybrid Vue admin + Next.js/React storefront; Next.js/React dua aplikasi dengan UI React.

## Trade-offs

Nuxt menyatukan Vue dan kontrak TypeScript. Hybrid mempertahankan pengalaman React SSR namun menggandakan review dan dependensi. React dua aplikasi cocok bila tim React dan boleh mengganti Nuxt UI.

## Decision

Nuxt 4 di admin (SPA + Nitro, Nuxt UI) dan storefront (SSR, UI custom).

## Consequences

Tim mengelola dua build dari ekosistem sama; SSR publik dan proxy cookie diuji terpisah.
