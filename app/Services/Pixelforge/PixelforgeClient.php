<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Pixelforge API. It never throws on API errors for writes:
 * callers branch on the response's error.code (see SubmitGenerationJob).
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    /** GET /pricing (public). Cached 10 minutes: rates can change, but not every second. */
    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(10), fn () => Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 500, throw: false)
            ->get('/pricing')
            ->throw()
            ->json());
    }

    /**
     * POST /generations. Same Idempotency-Key + same body = same generation, never a second one.
     * Only documented parameters may be sent (unknown ones are a 422).
     */
    public function createGeneration(string $idempotencyKey, array $body): Response
    {
        return $this->authed()
            ->timeout(15)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $body);
    }

    public function getGeneration(string $providerId): Response
    {
        return $this->authed()->timeout(10)->get('/generations/'.rawurlencode($providerId));
    }

    /** GET /usage: what Pixelforge billed this key, per UTC day (max 92 days). */
    public function usage(string $startDate, string $endDate): Response
    {
        return $this->authed()->timeout(10)->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    private function authed(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson();
    }
}
