<?php

namespace App\Services\Pixelforge;

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

/**
 * Processes a verified Pixelforge event exactly once.
 *
 * The event row (unique event_id) is inserted in the SAME transaction as the generation update:
 * - a duplicate delivery (even concurrent) inserts 0 rows and does nothing;
 * - if the update throws, the insert is rolled back too, we answer 500 and Pixelforge retries.
 */
class WebhookProcessor
{
    public function __construct(private readonly GenerationStateMachine $stateMachine) {}

    /** @return bool false when the event was a duplicate */
    public function process(array $event): bool
    {
        $object = $event['data']['object'] ?? null;

        return DB::transaction(function () use ($event, $object) {
            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => (string) $event['id'],
                'type' => (string) ($event['type'] ?? 'unknown'),
                'provider_generation_id' => is_array($object) ? ($object['id'] ?? null) : null,
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return false; // already processed
            }

            if (is_array($object) && ($object['object'] ?? null) === 'generation') {
                $this->stateMachine->applyWithinTransaction($object);
            }

            WebhookEvent::query()->where('event_id', $event['id'])->update(['processed_at' => now()]);

            return true;
        });
    }
}
