# PixelForge

An image-generation app that resells Pixelforge's simulated AI image provider to end users, charging the provider's cost plus a markup.

## Language

**Pixelforge**:
The upstream image-generation provider whose API this app calls. It bills per token and reports token usage, not prices.
_Avoid_: the API, the model, upstream

**Generation**:
One request to produce an image from a prompt, size, quality and optional reference images, tracked from submission to a final outcome.
_Avoid_: job, image request, render

**Generation Parameters**:
What the user chooses for a Generation: its prompt, size, quality and reference images. They set its Price Range and Maximum Price, and Try again reuses them.
_Avoid_: options, settings, request

**Generation Status**:
Where a Generation is in its lifecycle: `pending` (saved here, not yet accepted by Pixelforge), then `queued`, `processing`, and finally `succeeded` or `failed`. Status only moves forward; `succeeded` and `failed` are final.
_Avoid_: state, phase

**Webhook Event**:
A notification from Pixelforge that a Generation changed status. The same event may arrive more than once and in any order.
_Avoid_: callback, notification, hook

**Sandbox**:
Pixelforge's test environment, where a keyword in the prompt (`[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`) picks the Generation's outcome. Only the real Pixelforge environment, never a stand-in for it in tests.
_Avoid_: test mode, fake Pixelforge

## Submission

**Submission Attempt**:
One create request sent to Pixelforge for a Generation. A Generation gets 5 before it fails, or 20 while Pixelforge may already hold it or refuses our API key (ADR-0005). Concurrency-limit refusals in the first 15 minutes after Generate don't count.
_Avoid_: try, retry (when meaning the count)

**Answer**:
What Pixelforge said to one Submission Attempt: accepted, a Refusal, or none at all (a lost response, or an acceptance this app couldn't read or record).
_Avoid_: response, result

**Refusal**:
A create request Pixelforge didn't accept, by kind rather than error code or HTTP status: _Busy_ (rate limit or overload, retried), _Not Yet_ (the account's concurrency limit, waited out), _No Verdict_ (an undocumented server error; Pixelforge may have created it), _Key Refused_ (our API key, resent once fixed) or _Permanent_ (can never succeed, failed at once).
_Avoid_: rejection, error

**Unknown Outcome**:
Set once any Submission Attempt got no Answer or a No Verdict Refusal: Pixelforge may hold the Generation, so it stays `pending` for reconciliation's resends instead of failing after 5 attempts.
_Avoid_: maybe-created, lost

**Reconciliation**:
The every-minute `generations:reconcile` run that polls Pixelforge for Generations that went quiet, resends `pending` ones whose submission stalled, retries image downloads, and gives up on Generations Pixelforge never finishes (ADR-0007). It changes Generations; it never compares money.
_Avoid_: sync, polling job, reconciliation (when meaning the Usage check)

## Money

**Provider Cost**:
What Pixelforge bills for a succeeded Generation, computed from its token usage and the rates locked when it was created. A failed Generation has no Provider Cost.
_Avoid_: upstream cost, base price

**Markup**:
The 25% added on top of the Provider Cost to produce the Price.
_Avoid_: margin, fee, commission

**Price**:
What the user pays for a succeeded Generation: Provider Cost plus Markup, exact to the nanodollar, but never more than its Maximum Price (ADR-0004).
_Avoid_: cost (reserved for Provider Cost), fee, amount

**Maximum Price**:
The highest Price a Generation can possibly reach given its Generation Parameters, known before submission. It's the headline figure in the estimate shown to the user.
_Avoid_: quote, estimate (when meaning the single upper figure)

**Price Range**:
The lowest and highest Price a Generation can reach given its Generation Parameters, shown before submission.
_Avoid_: estimate band

**Credits**:
A user's prepaid funds, denominated in US dollars.
_Avoid_: tokens (reserved for Pixelforge usage), points, wallet

**Balance**:
The Credits a user holds: Top-ups minus Charges.
_Avoid_: funds, wallet

**Available Balance**:
Balance minus all active Holds; what a user can still commit to new Generations.
_Avoid_: free balance, spendable

**Top-up**:
Credits added to a Balance, by buying a Credit pack, as the starting grant, or when the reviewer account is set up.
_Avoid_: deposit, purchase, recharge

**Credit pack**:
A fixed amount of Credits ($5, $10 or $25) a user buys on the Buy credits page, adding one Top-up. The server sets each pack's amount; the client only names the pack.
_Avoid_: bundle, plan, product

**Hold**:
The Maximum Price set aside from the Available Balance when a Generation is submitted, until that Generation is final.
_Avoid_: reservation, authorization, pending charge

**Hold release**:
The part of a Hold returned to the Available Balance once its Generation is final: all of it on a failure, the Hold minus the Charge on a success, and nothing when the Price reached the Maximum Price. A Generation has at most one.
_Avoid_: refund, unhold

**Charge**:
The Price deducted from a Balance when a Generation succeeds. A Generation has at most one Charge; a failed Generation has none.
_Avoid_: debit, payment, bill

**Profit**:
The sum of Charges minus the sum of Provider Costs for the same Generations.
_Avoid_: margin, revenue

## Reporting

**Admin report**:
The admins-only page of Generations, Charges, Provider Cost and Profit per UTC day and per user over a date range, with each day's Provider Cost set beside what Pixelforge billed for it.
_Avoid_: dashboard, analytics, stats

**Usage check**:
The Admin report's comparison of each UTC day's Provider Cost with what Pixelforge billed for that day (`GET /v1/usage`), highlighting any day that differs. It only reports; it changes nothing.
_Avoid_: reconciliation, audit
