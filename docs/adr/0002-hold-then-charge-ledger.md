# Hold the Maximum Price, then Charge the actual Price, in an append-only ledger

The actual Price is only known after a Generation succeeds, but a user mustn't be able to overspend by submitting several Generations at once. So submitting places a Hold for the Maximum Price against the Available Balance (Balance minus active Holds); success converts it to one Charge for the exact Price and releases the remainder; failure releases it entirely. All of these are rows in an append-only ledger, and a unique constraint allows at most one Charge per Generation, so a duplicate webhook, an out-of-order event, or a polling result racing a webhook can never record money twice: the database refuses it, not just the application logic.

## Considered Options

- **Charge only after success**: allows a negative Balance when several Generations are in flight.
- **A mutable `balance` column**: no audit trail, and correctness depends on every code path getting the increment right exactly once.

## Consequences

The Balance is derived from the ledger (optionally cached), and the Admin report's per-user spend is just a sum of Charges.
