<?php

namespace App\Pixelforge;

/**
 * Stay `pending` without retrying now: reconciliation resends it, because
 * Pixelforge may already hold it or can't take it yet.
 */
final readonly class AwaitResend {}
