# Give up on Generations Pixelforge never finishes

Reconciliation polls every `queued` or `processing` Generation that has gone quiet, and the history polls the page for as long as any of the user's Generations isn't final. If Pixelforge never finishes one, or forgets it (a `404`), both would poll forever.

So a Generation still not final an hour after Generate is given up on. It ends as `failed` with the local code `never_finished` and no Provider Cost or Price, and its reason reads "Pixelforge never finished it". Reconciliation polls one last time first, so a Generation that did finish is recorded normally. Pixelforge documents 90 seconds or more for a Generation, so an hour is about 40 times that.

This bends ADR-0005's rule that a Generation only becomes `failed` locally once Pixelforge has definitely not created it. Pixelforge did accept this one, and if it ever succeeds afterwards, the success is ignored (a final Generation never changes) and its Provider Cost isn't recorded. That's accepted because the alternative is polling forever. Reconciling against `GET /v1/usage` (stretch spec) would show any such Generation.

## Considered Options

- **Keep polling forever**: never loses a late success, but spends the shared request limit and keeps the user's page polling.
- **Give up only on a `404`**: covers a forgotten Generation, but not one that is stuck.
