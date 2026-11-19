<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admins_cannot_see_the_report(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }

    public function test_it_groups_by_completed_day_and_user_and_compares_with_pixelforge_usage(): void
    {
        config(['services.pixelforge.api_key' => 'pf_test_key', 'services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $alice = User::factory()->create(['email' => 'alice@example.com']);

        // Created on the 1st at 23:59, completed on the 2nd: billed on the 2nd (UTC).
        Generation::factory()->create([
            'user_id' => $alice->id, 'status' => Generation::SUCCEEDED,
            'provider_cost_nano' => 56_560_000, 'price_nano' => 70_700_000,
            'created_at' => '2026-11-01 23:59:00', 'completed_at' => '2026-11-02 00:00:30',
        ]);
        Generation::factory()->create([
            'user_id' => $alice->id, 'status' => Generation::SUCCEEDED,
            'provider_cost_nano' => 100, 'price_nano' => 125, 'completed_at' => '2026-11-02 10:00:00',
        ]);
        Generation::factory()->create(['user_id' => $alice->id, 'status' => Generation::FAILED, 'price_nano' => 0, 'completed_at' => '2026-11-02 11:00:00']);

        Http::fake(['*/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => '2026-11-01', 'generations' => 0, 'cost_usd' => '0.000000000'],
            ['date' => '2026-11-02', 'generations' => 2, 'cost_usd' => '0.056560100'],
        ]])]);

        $this->actingAs($admin)->get('/admin/report?start=2026-11-01&end=2026-11-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Report')
                ->has('rows', 1)
                ->where('rows.0.day', '2026-11-02')
                ->where('rows.0.email', 'alice@example.com')
                ->where('rows.0.generations', 2)
                ->where('rows.0.charged', '0.070700125')
                ->where('rows.0.provider_cost', '0.056560100')
                ->where('rows.0.profit', '0.014140025')
                ->where('days.0.matches', true)
                ->where('totals.profit', '0.014140025'));
    }
}
