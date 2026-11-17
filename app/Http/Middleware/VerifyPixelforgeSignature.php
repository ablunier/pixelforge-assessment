<?php

namespace App\Http\Middleware;

use App\Pixelforge\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a webhook request with 400 unless Pixelforge signed its raw body
 * within the last 300 seconds. Runs before anything parses the body.
 */
class VerifyPixelforgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = new WebhookSignature(config('services.pixelforge.webhook_secret'));

        if (! $signature->verify((string) $request->header('Pixelforge-Signature'), $request->getContent(), now()->getTimestamp())) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        return $next($request);
    }
}
