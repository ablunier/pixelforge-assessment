# Store all money as integer nanodollars

Pixelforge bills in fractions of a cent and reports usage costs as unrounded nine-decimal strings, so every amount here (Provider Cost, Price, Maximum Price, Holds, Charges, Top-ups, Balances) is stored as a signed 64-bit integer count of nanodollars (1 USD = 1,000,000,000) behind a `Money` value object. Every published rate is a whole number of nanodollars per token (e.g. $5.00 per 1M tokens = 5,000), and a 25% Markup is ×5/4, which stays whole: arithmetic is exact, never touches floats, and sums compare directly against `GET /v1/usage`. Rounding happens only for display (4 decimals, exact value on hover); users are charged the exact Price, not a cent-rounded one.

## Considered Options

- **Cents**: loses the sub-cent precision that most Prices live in (a typical Price is about $0.014).
- **`decimal(20,9)` with brick/money**: also exact, but drags decimal-string handling through PHP, JSON and TypeScript; integers are simpler and sort and sum natively in Postgres.
- **Rounding Charges up to the cent**: simpler to read, but silently raises the effective Markup above 25%.
