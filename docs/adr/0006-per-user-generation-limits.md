# Limit how many Generations each user can start

Anyone can register ([story 1](https://github.com/ablunier/pixelforge-assessment/issues/1)) and there's no email verification, and until the [stretch spec](https://github.com/ablunier/pixelforge-assessment/issues/2)'s Holds (ADR-0002) nothing made a user pay before a Generation was submitted. Every Generation is still a real Pixelforge charge to the business, and each one uses up Pixelforge's account-wide limits (60 requests a minute, 3 at once, 500 a day), which every user shares. Without limits, one script could run up the bill and lock everyone else out with `daily_limit_exceeded`.

So Generate and Try again each check three per-user limits before creating anything. A user at a limit gets a plain-language error under `generate`, and nothing is created or sent to Pixelforge:

- **5 Generations in progress** (not yet final), counting `pending`. [Story 13](https://github.com/ablunier/pixelforge-assessment/issues/1)'s "several quick submissions just wait their turn" still holds for a handful, and Pixelforge only runs 3 at once anyway.
- **50 Generations in any 24 hours**, a tenth of Pixelforge's daily account limit.
- **10 a minute** (a named `generations` rate limiter on both routes). This catches bursts of Generations that fail at once and so never count as in progress. Live validation of the form (Precognition) creates nothing and isn't counted.

The checks live in the Form Requests (`App\Concerns\GenerationLimits`), not in `CreateGeneration`, so operator tools such as `reviewer:create --examples` aren't limited. Two Generates at the very same moment can both pass the count. That overshoot is at most one or two, and is accepted rather than locking the user's row on every Generate.

**Amendment: Balances and Holds.** Holds and Balances (ADR-0002) now exist: every account starts with $1.00 of Credits, and Generate places a Hold for the Maximum Price, so a user can only start what their Available Balance covers. The limits stay unchanged. Credit packs cost nothing yet (no real payments), so the Balance alone doesn't stop a script, and the limits still protect Pixelforge's account-wide limits, which every user shares.

## Considered Options

- **Turn off self-registration in production**: closes the hole, but story 1 requires registration.
- **Bring back email verification**: makes throwaway accounts slower to create, but mail isn't delivered yet (README), and it doesn't limit any single account.
- **Holds against a Balance (ADR-0002)**: the real fix, since users then pay for what they start. That was the [stretch spec](https://github.com/ablunier/pixelforge-assessment/issues/2), since built (see the amendment above). These limits stay useful after it, because they protect the shared provider limits.

## Consequences

Throwaway accounts still multiply the per-user limits. If that's seen in practice, the next steps are verification or a global daily cap.
