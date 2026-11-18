# Real example Generations for the reviewer in production

The spec says "a seeder creates the reviewer account and example Generations (succeeded, failed, and a `[duplicate]` case)". Locally and in tests, `db:seed` does that: the examples are made-up rows built through `GenerationLifecycle`.

In production, `db:seed` creates the account but not the examples. Made-up examples would carry `gen_` ids and Provider Costs that Pixelforge never issued, so they could never reconcile against `GET /v1/usage` (the stretch spec's admin report). Instead, `reviewer:create --examples` presses Generate once per sandbox keyword (`[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`) through the real pipeline. Pixelforge's sandbox then produces each outcome, and every Price matches its own records. Running it again only submits the examples the account is missing.

## Considered Options

- **Seed made-up examples in production too**: matches the spec's wording, but leaves records that disagree with Pixelforge's.
- **Seed nothing and let the reviewer generate**: no setup, but the reviewer would have to know the sandbox keywords to see every outcome.

## Consequences

The examples are real Generations, so they cost a few cents and use Pixelforge's shared limits once. Whether they show every outcome depends on the sandbox behaving as documented, so `docs/sandbox-verification.md` lists the checks to make by hand.
