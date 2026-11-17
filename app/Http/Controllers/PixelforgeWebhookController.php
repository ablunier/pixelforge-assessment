<?php

namespace App\Http\Controllers;

use App\Actions\ReceiveWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Receives Pixelforge's Webhook Events. The signature has already been
 * verified (VerifyPixelforgeSignature). Processing is a few row writes, well
 * within Pixelforge's 10-second delivery window.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveWebhookEvent $receiveWebhookEvent): Response
    {
        $attempt = $request->header('Pixelforge-Delivery-Attempt');

        try {
            $receiveWebhookEvent->handle($request->getContent(), is_numeric($attempt) ? (int) $attempt : null);
        } catch (InvalidArgumentException) {
            abort(Response::HTTP_BAD_REQUEST, 'Not a Pixelforge Webhook Event.');
        }

        return response()->noContent(Response::HTTP_OK);
    }
}
