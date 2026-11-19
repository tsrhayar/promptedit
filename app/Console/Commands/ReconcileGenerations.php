<?php

namespace App\Console\Commands;

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Pixelforge\GenerationStateMachine;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Polling fallback: webhook delivery is not guaranteed, so every minute we
 * 1) ask Pixelforge for generations stuck in queued/processing, and
 * 2) re-dispatch generations stuck in pending (same uuid = same Idempotency-Key, safe).
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile {--limit=10 : Max API calls per run (60 req/min account limit)}';

    protected $description = 'Poll Pixelforge for generations whose webhooks may have been lost';

    public function handle(PixelforgeClient $client, GenerationStateMachine $stateMachine): int
    {
        if (! $client->isConfigured()) {
            $this->warn('PIXELFORGE_API_KEY is not set, skipping.');

            return self::SUCCESS;
        }

        $polled = 0;
        $stale = Generation::query()
            ->whereIn('status', Generation::IN_FLIGHT)
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subMinutes(2)) // longest run is ~90 s with [slow]
            ->orderBy('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $response = $client->getGeneration($generation->provider_id);
            } catch (ConnectionException) {
                break; // provider unreachable, try again next minute
            }

            if ($response->successful()) {
                $stateMachine->apply($generation->id, $response->json());
                $generation->touch(); // don't poll it again for 2 minutes if still running
                $polled++;
            } elseif ($response->status() === 429) {
                break;
            } else {
                $this->warn("Could not poll {$generation->provider_id}: HTTP {$response->status()}");
            }
        }

        $redispatched = 0;
        Generation::query()
            ->where('status', Generation::PENDING)
            ->where('updated_at', '<', now()->subMinutes(5))
            ->each(function (Generation $generation) use (&$redispatched) {
                SubmitGenerationJob::dispatch($generation->id); // ShouldBeUnique: no-op if already queued
                $redispatched++;
            });

        $this->info("Polled {$polled} generation(s), re-dispatched {$redispatched} pending generation(s).");

        return self::SUCCESS;
    }
}
