<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    /** A queued generation whose max estimate is held from a $5 balance. */
    private function heldGeneration(array $attributes = []): Generation
    {
        $user = User::factory()->create();
        $ledger = app(CreditLedger::class);
        $ledger->purchase($user);

        return DB::transaction(function () use ($user, $ledger, $attributes) {
            $locked = User::lockForUpdate()->find($user->id);
            $generation = Generation::factory()->queued()->create(['user_id' => $user->id, ...$attributes]);
            $ledger->hold($locked, $generation);

            return $generation;
        });
    }

    public function test_it_rejects_a_bad_signature(): void
    {
        $generation = $this->heldGeneration();

        $this->postWebhook($this->event($this->generationObject($generation, 'succeeded')), secret: 'whsec_wrong')
            ->assertStatus(400);

        $this->assertSame(Generation::QUEUED, $generation->fresh()->status);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_it_rejects_a_missing_signature(): void
    {
        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"id":"evt_1"}')
            ->assertStatus(400);
    }

    public function test_it_rejects_an_old_timestamp(): void
    {
        $generation = $this->heldGeneration();

        $this->postWebhook($this->event($this->generationObject($generation, 'succeeded')), time() - 301)
            ->assertStatus(400);

        $this->assertSame(Generation::QUEUED, $generation->fresh()->status);
    }

    public function test_it_rejects_a_body_modified_after_signing(): void
    {
        $body = json_encode($this->event(['id' => 'gen_1', 'object' => 'generation', 'status' => 'processing']));
        $header = \App\Services\Pixelforge\WebhookSignature::sign($body, self::WEBHOOK_SECRET, time());

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $header,
        ], str_replace('processing', 'succeeded', $body))->assertStatus(400);
    }

    public function test_it_acknowledges_a_ping(): void
    {
        $this->postWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => null]])
            ->assertNoContent();
    }

    public function test_succeeded_records_the_exact_price_and_charges_the_balance(): void
    {
        $generation = $this->heldGeneration();

        $this->postWebhook($this->event($this->generationObject($generation, 'succeeded')))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::SUCCEEDED, $generation->status);
        $this->assertSame(self::SUCCEEDED_PROVIDER_COST, $generation->provider_cost_nano);
        $this->assertSame(self::SUCCEEDED_PRICE, $generation->price_nano);
        $this->assertSame('2026-11-04 14:03:29', $generation->completed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($generation->image_url);
        $this->assertSame(5_000_000_000 - self::SUCCEEDED_PRICE, $generation->user->fresh()->balance_nano);
    }

    public function test_a_duplicated_event_is_recorded_once_and_charged_once(): void
    {
        $generation = $this->heldGeneration();
        $event = $this->event($this->generationObject($generation, 'succeeded'), 'evt_DUPLICATE');

        $this->postWebhook($event)->assertNoContent();
        $this->postWebhook($event)->assertNoContent();

        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_DUPLICATE')->count());
        $this->assertSame(1, CreditTransaction::where('generation_id', $generation->id)->where('type', 'charge')->count());
        $this->assertSame(5_000_000_000 - self::SUCCEEDED_PRICE, $generation->user->fresh()->balance_nano);
    }

    public function test_two_different_succeeded_events_still_charge_once(): void
    {
        $generation = $this->heldGeneration();
        $object = $this->generationObject($generation, 'succeeded');

        $this->postWebhook($this->event($object, 'evt_A'))->assertNoContent();
        $this->postWebhook($this->event($object, 'evt_B'))->assertNoContent();

        $this->assertSame(2, WebhookEvent::count());
        $this->assertSame(1, CreditTransaction::where('generation_id', $generation->id)->where('type', 'charge')->count());
        $this->assertSame(self::SUCCEEDED_PRICE, $generation->fresh()->price_nano);
    }

    public function test_a_late_processing_event_never_moves_a_succeeded_generation_back(): void
    {
        $generation = $this->heldGeneration();

        $this->postWebhook($this->event($this->generationObject($generation, 'succeeded')))->assertNoContent();
        $this->postWebhook($this->event($this->generationObject($generation, 'processing')))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::SUCCEEDED, $generation->status);
        $this->assertSame(self::SUCCEEDED_PRICE, $generation->price_nano);
    }

    public function test_a_late_failed_event_does_not_override_succeeded(): void
    {
        $generation = $this->heldGeneration();

        $this->postWebhook($this->event($this->generationObject($generation, 'succeeded')))->assertNoContent();
        $this->postWebhook($this->event($this->generationObject($generation, 'failed')))->assertNoContent();

        $this->assertSame(Generation::SUCCEEDED, $generation->fresh()->status);
        $this->assertSame(self::SUCCEEDED_PRICE, $generation->fresh()->price_nano);
    }

    public function test_succeeded_can_skip_processing(): void
    {
        $generation = $this->heldGeneration();

        $this->postWebhook($this->event($this->generationObject($generation, 'processing')))->assertNoContent();
        $this->assertSame(Generation::PROCESSING, $generation->fresh()->status);

        $this->postWebhook($this->event($this->generationObject($generation, 'succeeded')))->assertNoContent();
        $this->assertSame(Generation::SUCCEEDED, $generation->fresh()->status);
    }

    public function test_a_failed_generation_is_free_and_releases_the_hold(): void
    {
        $generation = $this->heldGeneration();

        $this->postWebhook($this->event($this->generationObject($generation, 'failed')))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::FAILED, $generation->status);
        $this->assertSame(0, $generation->price_nano);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertSame(5_000_000_000, $generation->user->fresh()->balance_nano);
        $this->assertSame(0, CreditTransaction::where('type', 'charge')->count());
    }

    public function test_a_webhook_that_arrives_before_the_post_returned_finds_the_row_by_metadata(): void
    {
        $generation = $this->heldGeneration(['status' => Generation::PENDING, 'provider_id' => null]);
        $object = $this->generationObject($generation, 'processing', ['id' => 'gen_EARLY']);

        $this->postWebhook($this->event($object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::PROCESSING, $generation->status);
        $this->assertSame('gen_EARLY', $generation->provider_id);
    }

    public function test_an_unknown_generation_is_acknowledged_without_side_effects(): void
    {
        $object = [
            'id' => 'gen_OTHER', 'object' => 'generation', 'status' => 'succeeded',
            'metadata' => ['app_generation_id' => 'not-ours'], 'usage' => null, 'rates' => self::rates(),
        ];

        $this->postWebhook($this->event($object))->assertNoContent();

        $this->assertSame(0, Generation::count());
        $this->assertSame(1, WebhookEvent::count());
    }
}
