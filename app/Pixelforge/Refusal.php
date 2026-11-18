<?php

namespace App\Pixelforge;

/**
 * What kind of "no" Pixelforge gave a create request (ADR-0005), whatever
 * the error code or HTTP status it came with.
 */
enum Refusal
{
    /** `rate_limit_exceeded` or `overloaded`: nothing was created, and the same request may succeed later. */
    case Busy;

    /** `concurrency_limit_exceeded`: 3 Generations already in flight for the account, so not yet. */
    case NotYet;

    /**
     * Any other server error, such as a proxy's gateway timeout: like a lost
     * response, Pixelforge may still have created the Generation.
     */
    case NoVerdict;

    /** `invalid_api_key`: our misconfiguration, not the Generation's fault. */
    case KeyRefused;

    /** Anything else, e.g. `content_policy_violation`: the same request can never succeed. */
    case Permanent;
}
