# Submit to Pixelforge from a queued job, with a local `pending` status

Pixelforge's concurrency limit (3 queued or processing) and per-minute rate limit apply to the whole account, which every user of this app shares, and Pixelforge sometimes answers `503 overloaded`. So "Generate" only saves the Generation locally as `pending` and places its Hold; a queued job calls `POST /v1/generations` with the local ID as `Idempotency-Key` (and in `metadata`), retrying `429` and `503` after `Retry-After` with capped exponential backoff. Permanent rejections and exhausted retries move the Generation to `failed`, keeping the provider's error code, with no Provider Cost. The idempotency key makes job retries safe even when a request succeeded but its response was lost.

## Considered Options

- **Calling Pixelforge inside the HTTP request**: simpler, but surfaces transient limit and overload errors to users and can't queue behind the shared concurrency limit.

## Consequences

Generation Status has a fifth, local value, `pending`, ahead of Pixelforge's four. Webhooks can arrive before the job has saved Pixelforge's `gen_` id, so they're matched by the local ID in `metadata` first.
