<?php

namespace Tests\Feature;

use App\Jobs\SubmitGenerationJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class SubmitGenerationJobTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.pixelforge.api_key' => 'pf_test_key',
            'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        ]);
    }

    private function pendingGeneration(array $attributes = []): Generation
    {
        $user = User::factory()->create();
        $ledger = app(CreditLedger::class);
        $ledger->purchase($user);

        return DB::transaction(function () use ($user, $ledger, $attributes) {
            $generation = Generation::factory()->create(['user_id' => $user->id, ...$attributes]);
            $ledger->hold(User::lockForUpdate()->find($user->id), $generation);

            return $generation;
        });
    }

    private function runJob(Generation $generation): SubmitGenerationJob
    {
        $job = (new SubmitGenerationJob($generation->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    private static function error(string $code, string $type = 'rate_limit_error'): array
    {
        return ['error' => ['type' => $type, 'code' => $code, 'message' => "Error {$code}"]];
    }

    public function test_it_sends_only_documented_fields_with_idempotency_key_and_metadata(): void
    {
        $generation = $this->pendingGeneration(['reference_images' => ['https://example.com/a.png']]);
        Http::fake(['*/generations' => Http::response($this->generationObject($generation, 'queued', ['id' => 'gen_NEW']), 202)]);

        $this->runJob($generation)->assertNotReleased();

        Http::assertSent(function (Request $request) use ($generation) {
            return $request->url() === 'https://pixelforge.test/v1/generations'
                && $request->header('Idempotency-Key')[0] === $generation->uuid
                && $request->header('Authorization')[0] === 'Bearer pf_test_key'
                && array_keys($request->data()) === ['prompt', 'size', 'quality', 'metadata', 'reference_images']
                && $request['metadata'] === ['app_generation_id' => $generation->uuid, 'user_id' => (string) $generation->user_id];
        });

        $generation->refresh();
        $this->assertSame(Generation::QUEUED, $generation->status);
        $this->assertSame('gen_NEW', $generation->provider_id);
        $this->assertSame(1, $generation->submit_attempts);
    }

    public function test_reference_images_key_is_omitted_when_empty(): void
    {
        $generation = $this->pendingGeneration();
        Http::fake(['*/generations' => Http::response($this->generationObject($generation, 'queued'), 202)]);

        $this->runJob($generation);

        Http::assertSent(fn (Request $request) => ! array_key_exists('reference_images', $request->data()));
    }

    public function test_overloaded_is_retried_after_retry_after_with_the_same_idempotency_key(): void
    {
        $generation = $this->pendingGeneration();
        Http::fake(['*/generations' => Http::sequence()
            ->push(self::error('overloaded', 'api_error'), 503, ['Retry-After' => '2'])
            ->push($this->generationObject($generation, 'queued', ['id' => 'gen_RETRY']), 202, ['Idempotent-Replayed' => 'true']),
        ]);

        $first = $this->runJob($generation);
        $first->assertReleased();
        $this->assertGreaterThanOrEqual(2, $this->releaseDelay($first));
        $this->assertSame(Generation::PENDING, $generation->fresh()->status);

        $this->runJob($generation)->assertNotReleased();

        $this->assertSame(Generation::QUEUED, $generation->fresh()->status);
        $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertSame([$generation->uuid], $keys->values()->all());
    }

    public function test_overloaded_gives_up_after_the_retry_cap_and_is_free(): void
    {
        $generation = $this->pendingGeneration(['submit_attempts' => SubmitGenerationJob::MAX_TRANSIENT_ATTEMPTS - 1]);
        Http::fake(['*/generations' => Http::response(self::error('overloaded', 'api_error'), 503, ['Retry-After' => '2'])]);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(Generation::REJECTED, $generation->status);
        $this->assertSame('overloaded', $generation->error_code);
        $this->assertSame(0, $generation->price_nano);
        $this->assertSame(5_000_000_000, $generation->user->balance_nano); // hold released
    }

    public function test_rate_limit_respects_retry_after(): void
    {
        $generation = $this->pendingGeneration();
        Http::fake(['*/generations' => Http::response(self::error('rate_limit_exceeded'), 429, ['Retry-After' => '30'])]);

        $job = $this->runJob($generation);

        $job->assertReleased();
        $this->assertGreaterThanOrEqual(30, $this->releaseDelay($job));
        $this->assertSame(Generation::PENDING, $generation->fresh()->status);
    }

    public function test_concurrency_limit_waits_for_a_free_slot(): void
    {
        $generation = $this->pendingGeneration();
        Http::fake(['*/generations' => Http::response(self::error('concurrency_limit_exceeded'), 429, ['Retry-After' => '5'])]);

        $job = $this->runJob($generation);

        $job->assertReleased();
        $this->assertGreaterThanOrEqual(5, $this->releaseDelay($job));
        $this->assertSame(Generation::PENDING, $generation->fresh()->status);
    }

    public function test_it_does_not_call_pixelforge_while_three_generations_are_in_flight(): void
    {
        Generation::factory()->count(3)->queued()->create();
        $generation = $this->pendingGeneration();
        Http::fake();

        $this->runJob($generation)->assertReleased();

        Http::assertNothingSent();
        $this->assertSame(0, $generation->fresh()->submit_attempts);
    }

    public function test_content_policy_violation_is_rejected_and_free(): void
    {
        $generation = $this->pendingGeneration(['prompt' => 'a cat [blocked]']);
        Http::fake(['*/generations' => Http::response(self::error('content_policy_violation', 'invalid_request_error'), 400)]);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(Generation::REJECTED, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertStringContainsString('Nothing was charged', $generation->error_message);
        $this->assertSame(0, $generation->price_nano);
        $this->assertSame(5_000_000_000, $generation->user->balance_nano);
        $this->assertSame(0, CreditTransaction::where('type', 'charge')->count());
    }

    public function test_validation_failed_idempotency_conflict_and_daily_limit_are_final(): void
    {
        $cases = [[422, 'validation_failed'], [409, 'idempotency_key_reused'], [429, 'daily_limit_exceeded']];
        $sequence = Http::sequence();
        foreach ($cases as [$status, $code]) {
            $sequence->push(self::error($code, 'invalid_request_error'), $status);
        }
        Http::fake(['*/generations' => $sequence]);

        foreach ($cases as [, $code]) {
            $generation = $this->pendingGeneration();

            $this->runJob($generation)->assertNotReleased();

            $this->assertSame(Generation::REJECTED, $generation->fresh()->status, $code);
            $this->assertSame($code, $generation->fresh()->error_code);
            $this->assertSame(0, $generation->fresh()->price_nano);
        }
    }

    public function test_a_generation_already_accepted_by_webhook_is_not_resubmitted(): void
    {
        $generation = $this->pendingGeneration(['status' => Generation::PROCESSING, 'provider_id' => 'gen_X']);
        Http::fake();

        $this->runJob($generation);

        Http::assertNothingSent();
    }

    public function test_a_late_202_queued_response_does_not_move_back_a_generation_updated_by_webhook(): void
    {
        $generation = $this->pendingGeneration();
        // The webhook (processing) is applied while our POST is still in flight.
        Http::fake(function () use ($generation) {
            app(\App\Services\Pixelforge\GenerationStateMachine::class)
                ->applyWithinTransaction($this->generationObject($generation, 'processing', ['id' => 'gen_RACE']));

            return Http::response($this->generationObject($generation, 'queued', ['id' => 'gen_RACE']), 202);
        });

        $this->runJob($generation);

        $this->assertSame(Generation::PROCESSING, $generation->fresh()->status);
        $this->assertSame('gen_RACE', $generation->fresh()->provider_id);
    }

    private function releaseDelay(SubmitGenerationJob $job): int
    {
        return (int) $job->job->releaseDelay;
    }
}
