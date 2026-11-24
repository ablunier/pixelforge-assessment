<div align="center">

<img src="public/favicon.svg" alt="" height="96" />

# PixelForge

Generate images from a prompt and pay exactly the provider's cost plus 25%, never for a failure.

[![Deploy](https://img.shields.io/github/actions/workflow/status/ablunier/pixelforge-assessment/deploy.yml?branch=main&label=deploy&style=flat-square)](https://github.com/ablunier/pixelforge-assessment/actions/workflows/deploy.yml)
![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4?style=flat-square)
![Laravel](https://img.shields.io/badge/laravel-13-ff2d20?style=flat-square)

[Run locally](#run-locally) • [Tests](#tests) • [Architecture](#architecture) • [Money](#how-money-is-handled) • [Webhooks](#webhook-robustness) • [Limitations](#known-limitations-and-next-steps)

</div>

PixelForge is a web app that resells [Pixelforge](https://pixelforge.promptedit.dev/docs), a simulated AI image provider that bills per token and misbehaves on purpose. Users see the most a Generation can cost before they submit, watch it progress live, and pay its exact Price once it succeeds. The app absorbs Pixelforge's rate limits, overloads, failures and duplicate or out-of-order Webhook Events.

> [!TIP]
> It's live at <https://pixelforge.blunier.me>. Reviewers get their login privately with the submission.

## Features

- **Price before you submit**: the Maximum Price and the Price Range update as you type, and the final Price never exceeds the Maximum Price.
- **Exact Prices**: Provider Cost plus a 25% Markup, to the nanodollar, recorded exactly once. Failures cost nothing.
- **Live history**: on its own History page, every Generation, newest first, with its status updating live until it's final, its Price or a plain-language failure reason, and its image.
- **Waits its turn**: rate limits, the concurrency limit and overloads are retried in the background, so several quick submissions just work.
- **Try again**: one click on a failed Generation creates a new one with the same parameters.
- **Images that last**: each image is downloaded and kept after Pixelforge's 24-hour URL expires.
- **Credits**: every account starts with $1.00 and can buy $5, $10 or $25 packs (no real payment). A Generation the Available Balance can't cover is blocked, with a link to buy more.
- **Admin report**: daily and per-user Charges, Provider Cost and Profit, with each day's Provider Cost checked against what Pixelforge billed.

## Run locally

You need [Docker](https://docs.docker.com/get-docker/) with Compose, and [Node.js](https://nodejs.org/) with npm for the frontend build. The stack runs on [Laravel Sail](https://laravel.com/docs/sail).

```sh
cp .env.example .env
docker run --rm -v "$PWD":/app -w /app composer:2 composer install --ignore-platform-reqs   # first clone only
npm install && npm run build
docker compose up --build
./vendor/bin/sail artisan key:generate   # once, on a fresh .env
```

This starts the app (<http://localhost:8000>), a queue worker, the scheduler and Postgres. Migrations run automatically.

To talk to Pixelforge, create a **separate key for local development** in the Pixelforge dashboard and fill in `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`. Then restart the stack.

**A reviewer account with example history**, no Pixelforge key needed:

```sh
./vendor/bin/sail artisan db:seed
```

It prints the reviewer's generated password (or uses `REVIEWER_PASSWORD` from `.env`), makes the reviewer an admin with a $10.00 Balance, and seeds four finished Generations: a success, a `[duplicate]` success whose Webhook Events were applied once, a failure and a content-policy rejection. They're made up locally, so their thumbnails show the "image expired" placeholder.

### Receive webhooks through a tunnel

Pixelforge only delivers webhooks to a public URL. Without one, Generations still finish: reconciliation polls quiet ones every minute.

1. Expose port 8000, for example with a [Cloudflare quick tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/do-more-with-tunnels/trycloudflare/) or Sail's built-in Expose:

    ```sh
    cloudflared tunnel --url http://localhost:8000   # or: ./vendor/bin/sail share
    ```

2. In the Pixelforge dashboard, set the local key's webhook URL to `<tunnel-url>/webhooks/pixelforge`, the exact final URL: Pixelforge doesn't follow redirects.
3. Press **Send test event**. The delivery should show a `200`.

Quick-tunnel URLs change on every run, so update the webhook URL each time.

### Configuration

| Variable                    | Purpose                                                                            |
| --------------------------- | ---------------------------------------------------------------------------------- |
| `PIXELFORGE_BASE_URL`       | API base URL. Defaults to `https://pixelforge.promptedit.dev/v1`.                  |
| `PIXELFORGE_API_KEY`        | This environment's Pixelforge key. Use one key per environment.                    |
| `PIXELFORGE_WEBHOOK_SECRET` | The same key's webhook signing secret (`whsec_…`).                                 |
| `REVIEWER_EMAIL`            | The reviewer account's email, for `reviewer:create` and `db:seed`.                 |
| `REVIEWER_PASSWORD`         | Its password. Leave empty to have one generated and printed once. Never commit it. |

## Tests

Tests run against Postgres (the `testing` database, created by Sail) and never call the real Pixelforge API:

```sh
./vendor/bin/sail composer ci:check   # frontend checks, Pint, Larastan and the Pest suite
./vendor/bin/sail artisan test        # the Pest suite only
```

The suite tests at the app's outer edge: Inertia requests, signed webhook POSTs built from Pixelforge's documented signing scheme, queued jobs and the reconciliation command, with Pixelforge held in memory behind the `PixelforgeClient` seam. `HttpPixelforgeClient` has its own wire-contract tests against faked HTTP. Pricing also has its own unit tests. The checks against the real sandbox are manual, in [docs/sandbox-verification.md](docs/sandbox-verification.md).

## Architecture

A Laravel 13 + Inertia + Vue 3 (TypeScript) app on Postgres, with a database queue and the scheduler. Pressing Generate never calls Pixelforge inside the request ([ADR-0003](docs/adr/0003-queued-submission-with-pending-status.md)):

1. **Generate** validates the input and the user's limits ([ADR-0006](docs/adr/0006-per-user-generation-limits.md)), computes the Maximum Price on the server, saves the Generation as `pending` and queues its submission. If Pixelforge can't quote rates, the last rates it quoted are used.
2. **The submit job** sends it to Pixelforge with the local id as the `Idempotency-Key` and in `metadata`. It retries rate limits, concurrency waits, overloads, server errors and lost responses after `Retry-After` or a capped backoff, and ends permanent rejections and exhausted retries as `failed`. A Generation Pixelforge may already hold (its response was lost) is never failed early: it stays `pending` for reconciliation to resend, for up to 20 attempts ([ADR-0005](docs/adr/0005-submission-retries-and-giving-up.md)).
3. **Updates** arrive through signed Webhook Events, the create response, or reconciliation, which every minute polls Generations that went quiet and resubmits `pending` ones whose job died. All three go through the same lifecycle entry point. A Generation Pixelforge hasn't finished an hour after Generate is given up on ([ADR-0007](docs/adr/0007-giving-up-on-unfinished-generations.md)).
4. **On success** the image is downloaded in a queued job and served from local storage. Reconciliation retries a download that gave up for as long as Pixelforge's image URL works.

| Part                          | Where                                                                                                                                                                       |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Money value object            | [`app/Money`](app/Money)                                                                                                                                                    |
| Pricing rules                 | [`app/Pricing`](app/Pricing)                                                                                                                                                |
| Pixelforge HTTP client        | [`app/Pixelforge`](app/Pixelforge)                                                                                                                                          |
| Generate, lifecycle, webhooks | [`app/Actions`](app/Actions)                                                                                                                                                |
| Submit and image jobs         | [`app/Jobs`](app/Jobs)                                                                                                                                                      |
| Reconciliation, reviewer      | [`app/Console/Commands`](app/Console/Commands)                                                                                                                              |
| Admin report                  | [`app/Reports`](app/Reports), [`app/Actions/CompileAdminReport.php`](app/Actions/CompileAdminReport.php)                                                                    |
| Webhook endpoint              | [`routes/webhooks.php`](routes/webhooks.php), [`app/Http/Middleware/VerifyPixelforgeSignature.php`](app/Http/Middleware/VerifyPixelforgeSignature.php)                      |
| Generate and History pages    | [`resources/js/pages/Generate.vue`](resources/js/pages/Generate.vue), [`History.vue`](resources/js/pages/History.vue), [`resources/js/components`](resources/js/components) |

## How money is handled

- **Integer nanodollars.** Every amount is a 64-bit count of nanodollars ($1 = 1,000,000,000) behind the `Money` value object. Floats never touch money ([ADR-0001](docs/adr/0001-integer-nanodollars-for-money.md)). Pixelforge's rates are whole nanodollars per token (for example $5.00 per 1M tokens = 5,000), so the Provider Cost is exact.
- **Markup.** The Price is the Provider Cost × 5/4, exact to the nanodollar at Pixelforge's current rates (a future rate that left a remainder would be rounded half up to the nanodollar, see [ADR-0001](docs/adr/0001-integer-nanodollars-for-money.md)). Prices display to 4 decimals with the exact value on hover. The recorded Price is exact, never rounded to the cent.
- **Locked rates.** The Provider Cost uses the rates Pixelforge locked when it accepted the Generation, not today's rates.
- **Maximum Price.** It's computed on the server from the most text and reasoning tokens Pixelforge can bill. If Pixelforge locks higher rates than the estimate used, the Price is capped at the Maximum Price and the business absorbs the difference; the Provider Cost is still recorded at the locked rates ([ADR-0004](docs/adr/0004-price-capped-at-maximum-price.md)).
- **Recorded once.** The Price is written only on the single transition into `succeeded`, under the Generation's row lock, so a duplicate webhook, a poll and the create response racing each other can't record it twice.
- **Failures are free.** A failed or rejected Generation has no Provider Cost and no Price.

### Balances, Holds and Charges

Credits live in an append-only ledger of Top-ups, Holds, Hold releases and Charges ([ADR-0002](docs/adr/0002-hold-then-charge-ledger.md)). No row is ever updated or deleted, and there is no balance column: both figures are sums over the ledger.

- **Balance** = Top-ups − Charges. **Available Balance** = Balance − active Holds, what the user can still commit.
- **Top-ups.** Registration writes a $1.00 Top-up; Buy credits writes one for the chosen pack, whose amount the server picks from the pack id.
- **Hold on Generate.** Generate places a Hold for the Maximum Price in the same transaction that saves the `pending` Generation, after locking the user's row and checking the Available Balance. Concurrent submissions can't overspend, and one the Available Balance can't cover is rejected with nothing saved.
- **Charge on success.** The transition into `succeeded` writes one Charge for the exact Price and a Hold release for the rest of the Hold. The transition into `failed`, whether Pixelforge rejected it at submission or it failed later, releases the whole Hold.
- **Never twice.** A unique index allows one Charge and one Hold release per Generation, so a duplicate or out-of-order Webhook Event, or a poll racing a webhook, can't record money twice: the database refuses it.

### Admin report

Admins (the reviewer account, or anyone made one with `php artisan user:make-admin <email>`) see a report at `/admin/report` for a date range of up to 92 days, the last 7 by default:

- **Daily totals**: Generations, Charges (user spend), Provider Cost and Profit, counting each succeeded Generation on the UTC day it completed, as Pixelforge bills. Generations from before Balances existed have no Charge and count as zero spend.
- **Per-user totals** of the same figures.
- **Reconciliation**: each day's Provider Cost beside the `cost_usd` Pixelforge's `GET /v1/usage` reports for that day, parsed exactly into nanodollars. Any day where they differ is highlighted. If Pixelforge can't be reached, the report still shows the local figures. `/v1/usage` only covers the calling key, so the check is meaningful only in production against the production key.

## Webhook robustness

- **Verified**: the `Pixelforge-Signature` HMAC is checked against the raw body in constant time, with a 300-second tolerance. Bad or stale signatures get `400` and change nothing.
- **Deduplicated**: every event is stored with a unique event id, so a redelivery, a dashboard resend or a `[duplicate]` copy is acknowledged and not applied again.
- **Forward-only**: Generation Status only moves forward, and `succeeded` and `failed` are final, so a late `generation.processing` can't undo a success.
- **Early events**: events are matched by the local id in `metadata` first, so one that arrives before the create response still finds its Generation.
- **Fast**: processing is a few row writes in one transaction, well within Pixelforge's 10-second delivery window. `ping` is stored and acknowledged.
- **Lost events**: reconciliation polls any in-flight Generation that hasn't changed for a minute.

## Production

Live at <https://pixelforge.blunier.me>. Every push to `main` is tested and then deployed to the droplet. See [docs/deploy.md](docs/deploy.md) for the stack and the one-time manual setup (DNS, droplet bootstrap, GitHub secrets, the Pixelforge webhook URL).

The reviewer account is created in production with real Generations, one per sandbox keyword, so they match Pixelforge's own records. This is a deliberate departure from the spec's "a seeder creates … example Generations": `db:seed` still creates the account in production but skips the made-up examples, whose fake `gen_` ids and Provider Costs would never reconcile against Pixelforge's `GET /v1/usage` ([ADR-0008](docs/adr/0008-production-reviewer-examples.md)).

```sh
docker compose exec -e REVIEWER_EMAIL=<reviewer-email> app php artisan reviewer:create --examples
```

The command makes the reviewer an admin and tops their Balance up to $10.00 before submitting the examples, enough to then try every quality and size. Running it again adds Credits only if the Balance has dropped below $10.00.

[docs/sandbox-verification.md](docs/sandbox-verification.md) is the checklist for verifying `[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]` and `[overloaded]`, the webhook deliveries, the money each one moves and the admin report's reconciliation against the real sandbox.

## Known limitations and next steps

- **No real payments.** Buy credits adds Credits without charging anything. There are no refunds or manual ledger adjustments.
- **Reconciliation only flags.** A day whose Provider Cost differs from `/v1/usage` is highlighted in the report, with no alerting, and the report can't be exported.
- **One admin role.** `is_admin` is the only role. Admins are made from the command line, not in the app.
- **Shared provider limits.** Pixelforge's limits (60 requests a minute, 3 concurrent, 500 a day) apply to the whole Pixelforge account, across the local and production keys.
- **Per-user limits.** Each user may have 5 Generations in progress, 50 in any 24 hours and 10 a minute ([ADR-0006](docs/adr/0006-per-user-generation-limits.md)). Throwaway accounts still multiply these.
- **History capped at 50.** The history shows a user's 50 newest Generations, with no pagination yet.
- **Polling, not push.** The history polls every few seconds while a Generation is in flight. There are no WebSockets.
- **No automatic retry after acceptance.** A Generation that fails at Pixelforge stays failed. Users press "Try again".
- **Single droplet.** Images live on a Docker volume on one droplet, with no object storage or backups.
- **No email.** Mail goes to the log, so password-reset emails aren't delivered.

## Resources

- [GLOSSARY.md](GLOSSARY.md): the domain language (Generation, Provider Cost, Markup, Price, Maximum Price, ...).
- [docs/adr](docs/adr): architecture decisions ([0001](docs/adr/0001-integer-nanodollars-for-money.md) integer nanodollars, [0002](docs/adr/0002-hold-then-charge-ledger.md) Hold-then-Charge ledger, [0003](docs/adr/0003-queued-submission-with-pending-status.md) queued submission with a `pending` status, [0004](docs/adr/0004-price-capped-at-maximum-price.md) Price capped at the Maximum Price, [0005](docs/adr/0005-submission-retries-and-giving-up.md) submission retries and giving up, [0006](docs/adr/0006-per-user-generation-limits.md) per-user Generation limits, [0007](docs/adr/0007-giving-up-on-unfinished-generations.md) giving up on unfinished Generations, [0008](docs/adr/0008-production-reviewer-examples.md) real reviewer examples in production).
- [docs/deploy.md](docs/deploy.md): production stack and setup.
- [docs/sandbox-verification.md](docs/sandbox-verification.md): manual checks against the real sandbox.
- [Pixelforge API reference](https://pixelforge.promptedit.dev/docs).
