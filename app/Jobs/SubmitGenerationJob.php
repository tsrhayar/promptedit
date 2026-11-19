<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationStateMachine;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Sends one local generation to Pixelforge, retrying safely.
 *
 * - The local row already exists (status "pending"), so a webhook that arrives before the
 *   POST returns can still find it through metadata.app_generation_id.
 * - Idempotency-Key = our uuid, so a retry after a timeout/503 can never create (or bill)
 *   a second generation.
 * - Retry-After is respected, with growing backoff and a cap on attempts.
 * - At most 3 generations may be queued/processing per account: we wait for a free slot
 *   locally instead of burning requests, and still handle the provider's 429.
 */
class SubmitGenerationJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /** Max POST attempts for transient errors (503 overloaded, 429 rate limit, network). */
    public const MAX_TRANSIENT_ATTEMPTS = 6;

    /** Pixelforge allows 3 queued/processing generations per account. */
    public const CONCURRENCY_LIMIT = 3;

    /** Give up waiting for a free concurrency slot after this long. */
    public const MAX_WAIT_MINUTES = 15;

    public int $timeout = 30;

    public int $uniqueFor = 900;

    public function __construct(public int $generationId) {}

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    /** Attempts are capped by our own counters below; the queue only needs a time limit. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(self::MAX_WAIT_MINUTES + 15);
    }

    public function handle(PixelforgeClient $client, GenerationStateMachine $stateMachine): void
    {
        $generation = Generation::find($this->generationId);
        if (! $generation || $generation->status !== Generation::PENDING) {
            return; // already accepted (webhook or replay) or final
        }

        if (! $client->isConfigured()) {
            $stateMachine->reject($generation->id, 'not_configured', 'Image generation is not configured on this server (missing API key). Nothing was charged.');

            return;
        }

        if ($this->waitedTooLong($generation)) {
            $stateMachine->reject($generation->id, 'provider_busy', 'The image provider stayed busy for too long. Nothing was charged, please try again.');

            return;
        }

        // Local concurrency guard: don't even call Pixelforge while 3 generations are in flight.
        $inFlight = Generation::query()->whereIn('status', Generation::IN_FLIGHT)->count();
        if ($inFlight >= self::CONCURRENCY_LIMIT) {
            $this->release(5 + random_int(0, 3));

            return;
        }

        $generation->increment('submit_attempts');

        try {
            $response = $client->createGeneration($generation->uuid, $generation->toPixelforgePayload());
        } catch (ConnectionException $e) {
            // We don't know whether Pixelforge created it: stay "pending" and retry with the same key.
            Log::warning('Pixelforge create request failed, will retry', ['generation' => $generation->id, 'error' => $e->getMessage()]);
            $this->retryLater($generation, null);

            return;
        }

        if ($response->status() === 202) {
            $stateMachine->apply($generation->id, $response->json()); // same code path as the webhook

            return;
        }

        $this->handleError($generation, $response, $stateMachine);
    }

    private function handleError(Generation $generation, Response $response, GenerationStateMachine $stateMachine): void
    {
        $code = $response->json('error.code') ?? 'http_'.$response->status();
        $retryAfter = $this->retryAfter($response);

        switch ($code) {
            case 'overloaded':          // 503, nothing created
            case 'rate_limit_exceeded': // 429, too many requests this minute
                if ($generation->submit_attempts >= self::MAX_TRANSIENT_ATTEMPTS) {
                    $stateMachine->reject($generation->id, $code, $code === 'overloaded'
                        ? 'The image provider is overloaded right now. Nothing was charged, please try again in a moment.'
                        : 'Too many requests to the image provider. Nothing was charged, please try again in a minute.');

                    return;
                }
                $this->retryLater($generation, $retryAfter);

                return;

            case 'concurrency_limit_exceeded': // 429, a slot frees up within seconds
                $this->release(max($retryAfter ?? 5, 5) + random_int(0, 3));

                return;

            case 'content_policy_violation':
                $stateMachine->reject($generation->id, $code, 'Your prompt was rejected by the content policy. Nothing was charged.');

                return;

            case 'daily_limit_exceeded':
                $stateMachine->reject($generation->id, $code, 'The daily generation limit is reached. Nothing was charged, please try again after midnight UTC.');

                return;

            case 'validation_failed':
            case 'invalid_api_key':
            case 'idempotency_key_reused':
                Log::error('Pixelforge refused our request (configuration or code bug)', [
                    'generation' => $generation->id,
                    'status' => $response->status(),
                    'code' => $code,
                    'message' => $response->json('error.message'),
                    'param' => $response->json('error.param'),
                ]);
                $stateMachine->reject($generation->id, $code, 'The request could not be sent to the image provider (configuration error). Nothing was charged.');

                return;

            default:
                // Unknown/5xx error: Pixelforge might have created it, so keep it pending and
                // retry with the same Idempotency-Key. Reconcile picks it up if we give up here.
                Log::warning('Unexpected Pixelforge response', ['generation' => $generation->id, 'status' => $response->status(), 'code' => $code]);
                $this->retryLater($generation, $retryAfter);
        }
    }

    /** Retry-After + exponential backoff + jitter, capped at 60 s. */
    private function retryLater(Generation $generation, ?int $retryAfter): void
    {
        if ($generation->submit_attempts >= self::MAX_TRANSIENT_ATTEMPTS) {
            return; // stays pending; generations:reconcile re-dispatches it later (same key)
        }

        $backoff = 2 ** $generation->submit_attempts;
        $this->release(min(60, max($retryAfter ?? 0, $backoff) + random_int(0, 2)));
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? (int) $value : null;
    }

    private function waitedTooLong(Generation $generation): bool
    {
        return $generation->created_at->lt(now()->subMinutes(self::MAX_WAIT_MINUTES));
    }
}
