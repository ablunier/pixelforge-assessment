# Store all money as integer nanodollars

Pixelforge bills in fractions of a cent and reports usage costs as unrounded nine-decimal strings, so every amount here (Provider Cost, Price, Maximum Price, Holds, Charges, Top-ups, Balances) is stored as a signed 64-bit integer count of nanodollars (1 USD = 1,000,000,000) behind a `Money` value object. Every published rate is a whole number of nanodollars per token (e.g. $5.00 per 1M tokens = 5,000), and a 25% Markup is ×5/4, which stays whole: arithmetic is exact, never touches floats, and sums compare directly against `GET /v1/usage`. Rounding happens only for display (4 decimals, exact value on hover); users are charged the exact Price, not a cent-rounded one.

**Amendment: Markup rounding.** ×5/4 is exact only when the Provider Cost in nanodollars is divisible by 4. Every per-token cost Pixelforge publishes today (5,000, 10,000, 40,000 and 12,500 nanodollars) is, so every Price today is exact. Rates are only required to be a whole number of nanodollars per token, though, so a future rate such as `"12.51"` (12,510) could leave a remainder. Then the Price is rounded half up (away from zero) to the nanodollar: at most half a nanodollar off the exact value, and never above the Maximum Price, which caps it (ADR-0004). This is the one rounding outside display.

## Considered Options

- **Cents**: loses the sub-cent precision that most Prices live in (a typical Price is about $0.014).
- **`decimal(20,9)` with brick/money**: also exact, but drags decimal-string handling through PHP, JSON and TypeScript; integers are simpler and sort and sum natively in Postgres.
- **Rounding Charges up to the cent**: simpler to read, but silently raises the effective Markup above 25%.
- **Rejecting rates whose per-token cost isn't divisible by 4**: would keep every Price exact, but a harmless rate change would stop the app pricing, and estimating, anything.
- **Rounding the Markup down, always in the user's favour**: equally defensible at under a nanodollar; half up was kept as the conventional rule, symmetric with display rounding.
