<?php

namespace Tests\Feature;

use App\Jobs\SubmitGenerationJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.api_key' => 'pf_test_key', 'services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
        Http::fake(['*/pricing' => Http::response($this->pricingFixture())]);
        Queue::fake();
    }

    private function userWithCredits(): User
    {
        $user = User::factory()->create();
        app(CreditLedger::class)->purchase($user);

        return $user->fresh();
    }

    public function test_guests_cannot_generate(): void
    {
        $this->get('/generate')->assertRedirect('/login');
        $this->post('/generations', [])->assertRedirect('/login');
    }

    public function test_generate_page_receives_pricing_for_the_live_estimate(): void
    {
        $this->actingAs($this->userWithCredits())
            ->get('/generate')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations/Create')
                ->where('pricing.rates.output_tokens', '40.00')
                ->where('markupPercent', 25)
                ->where('balanceNano', 5_000_000_000));
    }

    public function test_submitting_creates_the_row_before_calling_pixelforge_and_holds_the_max(): void
    {
        $user = $this->userWithCredits();

        $this->actingAs($user)->post('/generations', [
            'prompt' => str_repeat('a', 40),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => ['', ' '],
            'estimated_max_nano' => 1, // ignored: the server recomputes
        ])->assertRedirect('/generations');

        $generation = Generation::sole();
        $this->assertSame(Generation::PENDING, $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertSame([], $generation->reference_images);
        $this->assertSame(58_175_000, $generation->estimated_min_nano);
        $this->assertSame(86_325_000, $generation->estimated_max_nano);
        $this->assertSame(5_000_000_000 - 86_325_000, $user->fresh()->balance_nano);
        Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $generation->id);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/generations'));
    }

    public function test_validation_mirrors_pixelforge_rules(): void
    {
        $this->actingAs($this->userWithCredits())->post('/generations', [
            'prompt' => str_repeat('x', 4001),
            'size' => '512x512',
            'quality' => 'ultra',
            'reference_images' => ['https://a.test/1.png', 'https://a.test/2.png', 'https://a.test/3.png', 'https://a.test/4.png', 'https://a.test/5.png'],
        ])->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images']);

        $this->actingAs($this->userWithCredits())->post('/generations', [
            'prompt' => 'ok', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => ['ftp://a.test/1.png'],
        ])->assertSessionHasErrors(['reference_images.0']);

        $this->assertSame(0, Generation::count());
    }

    public function test_submitting_without_enough_credits_is_blocked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', [
            'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'high',
        ])->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_buying_credits_adds_five_dollars_through_the_ledger(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits')->assertRedirect();

        $this->assertSame(5_000_000_000, $user->fresh()->balance_nano);
        $this->assertSame(5_000_000_000, (int) CreditTransaction::where('user_id', $user->id)->sum('amount_nano'));
    }

    public function test_buying_a_larger_credit_pack_adds_that_amount(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 25])->assertRedirect();

        $this->assertSame(25_000_000_000, $user->fresh()->balance_nano);
    }

    public function test_buying_an_unlisted_credit_pack_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 1000])->assertSessionHasErrors('amount');

        $this->assertSame(0, (int) $user->fresh()->balance_nano);
    }

    public function test_history_shows_status_estimate_and_actual_price(): void
    {
        $user = $this->userWithCredits();
        Generation::factory()->create([
            'user_id' => $user->id, 'status' => Generation::SUCCEEDED, 'provider_id' => 'gen_1',
            'price_nano' => 70_700_000, 'provider_cost_nano' => 56_560_000, 'image_url' => 'https://img.test/1.png',
            'image_url_expires_at' => now()->addDay(), 'completed_at' => now(),
        ]);
        Generation::factory()->create(['user_id' => $user->id, 'status' => Generation::FAILED, 'price_nano' => 0, 'error_message' => 'Boom']);
        Generation::factory()->create(); // someone else's

        $this->actingAs($user)->get('/generations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations/Index')
                ->has('generations', 2)
                ->where('generations.0.status', 'failed')
                ->where('generations.0.price', '0.000000000')
                ->where('generations.0.error_message', 'Boom')
                ->where('generations.1.price', '0.070700000')
                ->where('generations.1.estimated_max', '0.086325000'));
    }

    public function test_retry_creates_a_new_generation_with_a_new_idempotency_key(): void
    {
        $user = $this->userWithCredits();
        $failed = Generation::factory()->create(['user_id' => $user->id, 'status' => Generation::FAILED, 'price_nano' => 0]);

        $this->actingAs($user)->post("/generations/{$failed->id}/retry")->assertRedirect();

        $this->assertSame(2, Generation::count());
        $retry = Generation::latest('id')->first();
        $this->assertNotSame($failed->uuid, $retry->uuid);
        $this->assertSame($failed->prompt, $retry->prompt);
        Queue::assertPushed(SubmitGenerationJob::class);
    }

    public function test_users_cannot_retry_someone_elses_generation(): void
    {
        $failed = Generation::factory()->create(['status' => Generation::FAILED]);

        $this->actingAs($this->userWithCredits())->post("/generations/{$failed->id}/retry")->assertNotFound();
    }
}
