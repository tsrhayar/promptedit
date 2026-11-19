<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place where a local generation changes status. Used by the webhook, by the
 * response of POST /generations and by the polling fallback, so all three behave the same.
 *
 * Rules:
 * - Go by the generation object's status (data.object.status), not by the event type.
 * - Status only moves forward: pending -> queued -> processing -> succeeded|failed. A late
 *   "processing" after "succeeded" is ignored. Final states never change.
 * - The row is locked (SELECT ... FOR UPDATE) so two concurrent updates can't both charge.
 */
class GenerationStateMachine
{
    private const RANK = [
        Generation::PENDING => 0,
        Generation::QUEUED => 1,
        Generation::PROCESSING => 2,
        Generation::SUCCEEDED => 3,
        Generation::FAILED => 3,
        Generation::REJECTED => 3,
    ];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly CreditLedger $ledger,
    ) {}

    /** Apply a Pixelforge generation object to a known local row (job response, polling). */
    public function apply(int $localId, array $object): ?Generation
    {
        return DB::transaction(fn () => $this->applyWithinTransaction($object, $localId));
    }

    /**
     * Must run inside a transaction. Finds the row by local id, provider id, or our uuid in
     * metadata (a webhook can arrive before the POST response gave us the provider id).
     */
    public function applyWithinTransaction(array $object, ?int $localId = null): ?Generation
    {
        $generation = $this->findLocked($object, $localId);
        if (! $generation) {
            Log::warning('Pixelforge generation does not match any local row', ['provider_id' => $object['id'] ?? null]);

            return null;
        }

        $newStatus = $object['status'] ?? null;
        if (! isset(self::RANK[$newStatus]) || in_array($newStatus, [Generation::PENDING, Generation::REJECTED], true)) {
            Log::warning('Unknown Pixelforge status', ['status' => $newStatus, 'generation' => $generation->id]);

            return $generation;
        }

        if ($generation->provider_id === null && isset($object['id'])) {
            $generation->provider_id = $object['id'];
        }

        if (self::RANK[$newStatus] <= self::RANK[$generation->status]) {
            if ($generation->status === Generation::REJECTED) {
                Log::warning('Pixelforge update for a generation we marked rejected', ['generation' => $generation->id]);
            }
            $generation->save(); // only keeps a newly learned provider_id

            return $generation;
        }

        $generation->status = $newStatus;
        $generation->rates = $object['rates'] ?? $generation->rates;
        $generation->provider_created_at = $object['created_at'] ?? $generation->provider_created_at;
        $generation->started_at = $object['started_at'] ?? $generation->started_at;

        if ($newStatus === Generation::SUCCEEDED) {
            $actual = $this->pricing->actual($object); // uses the generation's frozen rates
            $generation->fill([
                'usage' => $object['usage'],
                'provider_cost_nano' => $actual['provider_cost_nano'],
                'price_nano' => $actual['price_nano'],
                'image_url' => $object['image_url'] ?? null,
                'image_url_expires_at' => $object['image_url_expires_at'] ?? null,
                'completed_at' => $object['completed_at'] ?? now(), // Pixelforge's date: it bills on that UTC day
            ]);
        }

        if ($newStatus === Generation::FAILED) {
            $generation->fill([
                'provider_cost_nano' => 0,
                'price_nano' => 0, // failed generations are never billed
                'error_code' => $object['error']['code'] ?? 'generation_failed',
                'error_message' => $object['error']['message'] ?? 'The generation failed.',
                'completed_at' => $object['completed_at'] ?? now(),
            ]);
        }

        $generation->save();

        if ($generation->isFinal()) {
            $this->ledger->settle($generation);
        }

        return $generation;
    }

    /** Pixelforge refused the create request: nothing was created there, nothing is billed. */
    public function reject(int $localId, string $code, string $message): void
    {
        DB::transaction(function () use ($localId, $code, $message) {
            $generation = Generation::query()->lockForUpdate()->find($localId);
            if (! $generation || $generation->status !== Generation::PENDING) {
                return; // accepted in the meantime (e.g. webhook arrived first)
            }

            $generation->fill([
                'status' => Generation::REJECTED,
                'error_code' => $code,
                'error_message' => $message,
                'provider_cost_nano' => 0,
                'price_nano' => 0,
                'completed_at' => now(),
            ])->save();

            $this->ledger->settle($generation);
        });
    }

    private function findLocked(array $object, ?int $localId): ?Generation
    {
        if ($localId !== null) {
            return Generation::query()->lockForUpdate()->find($localId);
        }

        $providerId = $object['id'] ?? null;
        $uuid = $object['metadata']['app_generation_id'] ?? null;
        if ($providerId === null && $uuid === null) {
            return null;
        }

        return Generation::query()
            ->lockForUpdate()
            ->where(function ($query) use ($providerId, $uuid) {
                if ($providerId !== null) {
                    $query->orWhere('provider_id', $providerId);
                }
                if ($uuid !== null) {
                    $query->orWhere('uuid', $uuid);
                }
            })
            ->first();
    }
}
