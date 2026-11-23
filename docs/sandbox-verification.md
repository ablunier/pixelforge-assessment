# Sandbox verification (production)

A manual end-to-end check against the real Pixelforge sandbox, run by the owner in production before submission. It uses the reviewer account and leaves its Generations in place for the reviewers. Automated tests fake Pixelforge, so this is the only check against the real thing.

Run the commands from `/opt/stacks/pixelforge` on the droplet. The `docker compose` commands below run the stack there.

## 1. Before you start

- [ ] The latest `main` is deployed: `curl -fsS https://pixelforge.blunier.me/up`.
- [ ] `/opt/stacks/pixelforge/.env` has the **production** key's `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`, not the local ones.
- [ ] In the Pixelforge dashboard, the production key's webhook URL is exactly `https://pixelforge.blunier.me/webhooks/pixelforge`, with no trailing slash and over `https`: Pixelforge doesn't follow redirects.
- [ ] Press **Send test event** in the dashboard. The delivery shows a `200` response, and the `ping` is stored:

    ```sh
    docker compose exec app php artisan tinker --execute 'dump(App\Models\WebhookEvent::query()->where("type", "ping")->latest("id")->first()?->only("event_id", "received_at"));'
    ```

## 2. Create the reviewer account and submit the examples

```sh
docker compose exec -e REVIEWER_EMAIL=<reviewer-email> app php artisan reviewer:create --examples
```

- [ ] It prints `Password: ...` once. Save it in your password manager and send it privately with the submission. Never commit it or paste it into an issue. (Set `REVIEWER_PASSWORD` in `.env` instead if you want to choose it, then recreate the containers.)
- [ ] It reports `submitted` for each of the six keywords. Running it again submits only missing examples and leaves the password unchanged.

The examples are low quality at 1024x1024, about $0.014 each at most. Three of them can be in flight at once (Pixelforge's concurrency limit), so the rest wait their turn for a little while.

## 3. Check each keyword

Log in at <https://pixelforge.blunier.me> as the reviewer and watch the history update live. Then check the stored values:

```sh
docker compose exec app php artisan tinker --execute '
$reviewer = App\Models\User::query()->where("email", "<reviewer-email>")->sole();
dump($reviewer->generations()->oldest()->get()->map(fn ($g) => [
    "prompt" => $g->prompt, "status" => $g->status->value, "error" => $g->error_code,
    "max_price" => $g->max_price->formatExact(), "provider_cost" => $g->provider_cost?->formatExact(),
    "price" => $g->price?->formatExact(), "attempts" => $g->submission_attempts, "image" => $g->image_path,
    "events" => App\Models\WebhookEvent::query()->where("generation_id", $g->id)->pluck("type")->all(),
])->all());'
```

| Keyword        | Expected in the history                                                                                  | Expected in the data                                                                                                                                                                   |
| -------------- | -------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `[succeed]`    | Submitting… → queued → processing → succeeded within about 30 s, with a thumbnail and the Price.         | `price` = `provider_cost` × 1.25 exactly, and not above `max_price`. `image` is set (the PNG is stored). Events: `generation.processing`, `generation.succeeded`.                      |
| `[fail]`       | Ends `failed` with "The model couldn't produce an image" and no Price. "Try again" is offered.           | `error` = `generation_failed`, `provider_cost` and `price` empty. Events include `generation.failed`.                                                                                  |
| `[slow]`       | Stays `processing` for 60 to 90 s, then succeeds.                                                        | As `[succeed]`. Live polling stops only once it's final.                                                                                                                               |
| `[duplicate]`  | Succeeds once, with one Price.                                                                           | Each event type is stored **once** although Pixelforge sent each twice; `price` = `provider_cost` × 1.25. The dashboard (step 4) shows two deliveries per event id, both answered 200. |
| `[blocked]`    | Ends `failed` straight away with "Blocked by content policy" and no Price.                               | `error` = `content_policy_violation`, no `gen_` id, no events, `provider_cost` and `price` empty.                                                                                      |
| `[overloaded]` | Stays "Submitting…" while it retries, then ends `failed` with "Provider busy, gave up after 5 attempts". | `error` = `retries_exhausted`, no `gen_` id, `price` empty. The queue log (`docker compose logs queue`) shows the retries spaced out by `Retry-After` and the backoff.                             |

- [ ] All six behave as in the table.
- [ ] Press "Try again" on the `[fail]` Generation: a new Generation with the same parameters appears and fails the same way. The original keeps its own outcome.

## 4. Webhook deliveries in the Pixelforge dashboard

- [ ] Every delivery for the production key shows a `2xx` response, including both copies of each `[duplicate]` event.
- [ ] Resend one `generation.succeeded` event from the dashboard. It's answered 200, nothing changes in the history, and the Generation's Price is the same as before.
- [ ] No delivery shows a `3xx` (a redirect means the webhook URL isn't the final URL) or a `400` (a `400` means the signing secret in `.env` doesn't match the key).

## 5. The numbers match Pixelforge's records

A Generation is billed on the UTC day it completed. Compare Pixelforge's usage for the production key with the stored Provider Costs for the same day:

```sh
curl -fsS "https://pixelforge.promptedit.dev/v1/usage?start_date=<yyyy-mm-dd>&end_date=<yyyy-mm-dd>" \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY"

docker compose exec app php artisan tinker --execute '
$day = Carbon\CarbonImmutable::parse("<yyyy-mm-dd>", "UTC");
$done = App\Models\Generation::query()->where("status", "succeeded")->whereBetween("completed_at", [$day, $day->endOfDay()])->get();
dump(["generations" => $done->count(), "cost_usd" => $done->reduce(fn ($sum, $g) => $sum->plus($g->provider_cost), App\Money\Money::ofNanodollars(0))->formatExact()]);'
```

- [ ] `generations` and `cost_usd` match the day's `total` from `/v1/usage` exactly. Failed and rejected Generations add nothing to either.

## 6. Leave it in place

- [ ] Don't delete the reviewer's Generations: the reviewers compare them with Pixelforge's records.
- [ ] Tick the matching criteria on issue #12.
