<?php

namespace App\Http\Controllers;

use App\Services\Pixelforge\WebhookProcessor;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/pixelforge (CSRF-excluded). Verifies the signature on the RAW body before
 * decoding anything, then processes the event inline: it is a few SQL queries, far below the
 * 10 s delivery timeout, and a 2xx then really means "recorded".
 */
class PixelforgeWebhookController
{
    public function __invoke(Request $request, WebhookSignature $signature, WebhookProcessor $processor): Response
    {
        $rawBody = $request->getContent();

        if (! $signature->isValid($rawBody, $request->header('Pixelforge-Signature'))) {
            Log::notice('Rejected Pixelforge webhook with an invalid signature', [
                'event_id' => $request->header('Pixelforge-Event-Id'),
            ]);

            return response('Invalid signature.', 400);
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! isset($event['id'])) {
            return response('Invalid payload.', 400);
        }

        if (($event['type'] ?? null) === 'ping') {
            return response()->noContent();
        }

        $processor->process($event);

        return response()->noContent();
    }
}
