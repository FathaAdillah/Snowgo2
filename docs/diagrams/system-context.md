# System context

```mermaid
flowchart LR
    C[Pembeli] --> S[Platform Snowego]
    A[Staff] --> S
    S --> G[Google OAuth]
    S --> E[SMTP / email]
    C --> B[Bank transfer]
    A --> B
    S -. Phase 2 .-> P[Tracking atau payment provider]
    S --> K[Kurir domestik]
    A --> U[Supplier luar negeri]
```
