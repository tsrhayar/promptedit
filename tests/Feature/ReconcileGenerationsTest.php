<?php

namespace Tests\Feature;

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class ReconcileGenerationsTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.api_key' => 'pf_test_key', 'services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    }

    public function test_it_polls_stale_generations_when_webhooks_were_lost(): void
    {
        $stale = Generation::factory()->queued()->create(['updated_at' => now()->subMinutes(5)]);
        $fresh = Generation::factory()->queued()->create();
        Http::fake(["*/generations/{$stale->provider_id}" => Http::response($this->generationObject($stale, 'succeeded'))]);

        $this->artisan('generations:reconcile')->assertSuccessful();

        $this->assertSame(Generation::SUCCEEDED, $stale->fresh()->status);
        $this->assertSame(self::SUCCEEDED_PRICE, $stale->fresh()->price_nano);
        $this->assertSame(Generation::QUEUED, $fresh->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_it_redispatches_stuck_pending_generations(): void
    {
        Queue::fake();
        $stuck = Generation::factory()->create(['updated_at' => now()->subMinutes(10)]);
        Generation::factory()->create(); // just created, its job is still running

        $this->artisan('generations:reconcile')->assertSuccessful();

        Queue::assertPushed(SubmitGenerationJob::class, 1);
        Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $stuck->id);
    }
}
