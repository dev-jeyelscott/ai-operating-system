# AIOS-168 Container Image Evidence

Verification date: 2026-07-19T09:55:38Z

| Usage | Verified immutable reference |
|---|---|
| PostgreSQL local and CI | `postgres:18.4-alpine3.24@sha256:9a8afca54e7861fd90fab5fdf4c42477a6b1cb7d293595148e674e0a3181de15` |
| Redis local and CI | `redis:8.8.0-alpine3.23@sha256:9d317178eceac8454a2284a9e6df2466b93c745529947f0cd42a0fa9609d7005` |
| MinIO local development | `minio/minio:RELEASE.2025-09-07T16-13-09Z@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e` |
| Mailpit local development | `axllent/mailpit:v1.30.4@sha256:5a49a77c5bdbe7c5474450b4f46348d09949df3695257729c93a30369382d4f6` |
| Composer clean-clone bootstrap | `composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760` |

The SHA-256 values above are multi-platform OCI index digests resolved directly
from the official container registries using:

```bash
docker buildx imagetools inspect IMAGE_REFERENCE
```

The manifests were verified to support:

- `linux/amd64`
- `linux/arm64`

Raw registry inspection output is stored in
`docs/evidence/AIOS-168-container-image-inspection.txt`.
