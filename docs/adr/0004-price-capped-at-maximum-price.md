# Cap the Price at the Maximum Price the user was shown

The Maximum Price is a promise ([story 19](https://github.com/ablunier/pixelforge-assessment/issues/1)), but it's computed at submission from `GET /v1/pricing`, which is cached for about an hour, while the Provider Cost uses the rates Pixelforge locks when it accepts the Generation. If Pixelforge raises its rates in between, the Provider Cost plus Markup can exceed the Maximum Price. So the Price is `min(Provider Cost × 5/4, Maximum Price)`: the Provider Cost is always recorded truthfully at the locked rates (it must match `GET /v1/usage`), and when the cap bites, the business absorbs the difference out of its Markup. At unchanged rates the cap never bites, because the Maximum Price already assumes the most text and reasoning tokens Pixelforge can bill.

## Considered Options

- **Refresh the rates on every submit**: shrinks the window but doesn't close it (Pixelforge locks its rates when the queued job's request arrives, possibly minutes later), and it puts a provider call back into the Generate request path. Not done; the cap makes the promise hold regardless.
- **Fail or re-confirm the Generation when locked rates are higher**: the image already exists and Pixelforge has billed it, so this would cost the business the whole Provider Cost and the user their image.
- **Charge the uncapped Price**: breaks the promise the headline figure makes.

## Consequences

Profit on a capped Generation is below 25%, and can be negative if rates rise by more than the Markup. A Price equal to the Maximum Price with a Provider Cost above `Maximum Price × 4/5` marks such a Generation.
