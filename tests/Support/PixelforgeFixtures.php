<?php

namespace Tests\Support;

use App\Models\Generation;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Payloads shaped exactly like the Pixelforge docs. */
trait PixelforgeFixtures
{
    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function pricingFixture(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => self::rates(),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    protected static function rates(): array
    {
        return ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];
    }

    protected function generationObject(Generation $generation, string $status, array $overrides = []): array
    {
        $object = [
            'id' => $generation->provider_id ?? 'gen_'.Str::upper(Str::random(26)),
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?? [],
            'metadata' => ['app_generation_id' => $generation->uuid, 'user_id' => (string) $generation->user_id],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => self::rates(),
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => in_array($status, ['processing', 'succeeded', 'failed'], true) ? '2026-11-04T14:03:13Z' : null,
            'completed_at' => null,
        ];

        if ($status === 'succeeded') {
            $object['usage'] = ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2112];
            $object['image_url'] = 'https://pixelforge.promptedit.dev/images/test.png?expires=1&signature=x';
            $object['image_url_expires_at'] = now()->addDay()->toIso8601ZuluString();
            $object['completed_at'] = '2026-11-04T14:03:29Z';
        }

        if ($status === 'failed') {
            $object['error'] = ['code' => 'generation_failed', 'message' => 'The model could not generate this image.'];
            $object['completed_at'] = '2026-11-04T14:03:29Z';
        }

        return array_replace($object, $overrides);
    }

    /** 12*5000 + 1100*40000 + 1000*12500 = 56_560_000 nano provider cost for the "succeeded" fixture. */
    protected const SUCCEEDED_PROVIDER_COST = 56_560_000;

    protected const SUCCEEDED_PRICE = 70_700_000; // * 1.25

    protected function event(array $object, ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.Str::upper(Str::random(26)),
            'object' => 'event',
            'type' => 'generation.'.$object['status'],
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $object],
        ];
    }

    protected function postWebhook(array $event, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        $body = json_encode($event);
        $header = WebhookSignature::sign($body, $secret ?? self::WEBHOOK_SECRET, $timestamp ?? time());

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $header,
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? '',
        ], $body);
    }
}
