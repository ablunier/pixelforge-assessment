# Coding Standards

Stack: Laravel 13 (PHP ^8.3), Inertia 3 + Vue 3 (TypeScript, `<script setup>`), Tailwind 4, Pest 5, Larastan, Pint, Wayfinder.
Domain language lives in `GLOSSARY.md`; decisions in `docs/adr/`. Code must agree with both.

## Style

### PHP
- Code must pass `composer lint:check` (Pint, `laravel` preset) and `composer types:check` (Larastan level 7). Don't suppress static-analysis errors with ignores or baselines without a stated reason.
- Declare parameter and return types on every method; add `@param`/`@return` generics docblocks only where native types can't express them (e.g. `array<int, string>`, `HasMany<Generation, $this>`).
- Use constructor property promotion, `readonly` where state doesn't change, and backed enums for closed sets of values (e.g. Generation Status), never string constants or magic strings.
- Use the exact domain terms from `GLOSSARY.md` for class, method, column and variable names (e.g. `Generation`, `Hold`, `Charge`, not `Job`, `Reservation`, `Payment`). Don't use the terms the glossary lists under _Avoid_.
- Use `config()` in code, never `env()` outside `config/*.php`.
- Prefer Eloquent and the query builder over raw SQL. Any raw expression must use bindings.

### Money
- Money is an integer count of nanodollars wrapped in the `Money` value object (ADR 0001). Never use floats, `round()` or decimal strings for amounts, and never do money arithmetic on bare ints outside `Money`. Round only for display.

### Vue / TypeScript
- Use `<script setup lang="ts">`. No `any`; type props, emits and Inertia page props explicitly (shared types in `resources/js/types`).
- Must pass `npm run check` (`vp check`) and `npm run types:check`.
- Components: PascalCase file names. Composables: `useXxx.ts` in `resources/js/composables`. Reuse the existing `components/ui` primitives before adding new ones.
- Use Wayfinder-generated route/action helpers from `resources/js/routes` and `resources/js/actions`, never hard-coded URL strings. Don't hand-edit generated directories (`wayfinder`, `actions`, `routes`).
- Style with Tailwind utility classes and `cn()` for conditional classes. No inline `style` or new CSS files unless unavoidable.

## Testing

- Use Pest (`it()` / `test()`), not PHPUnit classes. Feature tests live in `tests/Feature` (with `RefreshDatabase`), pure logic in `tests/Unit`.
- Test behaviour through public interfaces (HTTP requests, Actions, jobs), not private methods or implementation details.
- Names describe the expected behaviour: `it('releases the Hold when a Generation fails')`.
- Every new route, Action, job and value object has tests covering the happy path plus the relevant failure, authorization and validation cases.
- Money, ledger and Generation Status code needs tests for the edge cases the ADRs call out: duplicate or out-of-order Webhook Events, forward-only status transitions, one Charge per Generation, Holds released on failure.
- Never call the real Pixelforge API in tests. Use `Http::fake()` and `Queue::fake()` / `Event::fake()` as needed. Use model factories, not hand-built rows.
- A bug fix includes a regression test that fails without the fix.
- `composer test` (lint, Larastan, `artisan test`) and `npm run ci:check` must pass.

## Architecture

- Controllers stay thin: authorize, validate through a Form Request (`app/Http/Requests`), delegate to an Action or service, return an Inertia response or redirect. No business logic or queries beyond simple lookups in controllers.
- Business operations are single-purpose Actions in `app/Actions`; reusable validation rules go in `app/Concerns` traits. Keep modules focused on one responsibility and prefer composition over inheritance.
- Define interfaces (seams) at external boundaries, notably the Pixelforge HTTP client, so they can be faked in tests. Keep Pixelforge-specific payloads and error codes inside that boundary.
- Authorization via policies/gates, never inline role checks. Scope queries to the authenticated user. Never trust client-supplied ids or prices; compute the Price and Maximum Price server-side.
- Ledger rows (Holds, Charges, Top-ups) are append-only, and the Balance is derived from them (ADR 0002). Never update or delete ledger rows or add a mutable balance column. Enforce invariants with database constraints (e.g. one Charge per Generation), not only application checks.
- Generation Status only moves forward and `succeeded`/`failed` are final. Webhook handling must be idempotent and order-independent. Calls to Pixelforge are made from queued jobs with the local Generation ID as the `Idempotency-Key` (ADR 0003), never inside an HTTP request.
- Multi-step writes that must stay consistent (Hold + Generation, Charge + Hold release) run in a database transaction.
- Schema changes go through new migrations (never edit a merged one) with foreign keys and indexes on columns used for lookups. Use `$fillable`, never `$guarded = []`. Avoid N+1 queries: eager load, and consider `Model::preventLazyLoading()` in non-production.
- Secrets and API keys come from config/env, are never logged or sent to the frontend, and webhook requests must have their signature verified.
- Record significant new architectural decisions as an ADR in `docs/adr/` and new domain terms in `GLOSSARY.md`.
