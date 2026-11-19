<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\Money;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per UTC day (by completed_at, the day Pixelforge bills) and per user: what we charged,
 * what Pixelforge charged us, and the profit. Daily totals are compared with GET /v1/usage.
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        Gate::authorize('view-admin-report');

        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);
        $end = Carbon::parse($validated['end'] ?? now('UTC')->toDateString(), 'UTC')->endOfDay();
        $start = Carbon::parse($validated['start'] ?? $end->copy()->subDays(29)->toDateString(), 'UTC')->startOfDay();
        if ($start->diffInDays($end) > 91) {
            $start = $end->copy()->subDays(91)->startOfDay(); // /v1/usage accepts at most 92 days
        }

        $rows = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', Generation::SUCCEEDED)
            ->whereBetween('generations.completed_at', [$start, $end])
            ->selectRaw('DATE(generations.completed_at) as day, users.id as user_id, users.email as email, COUNT(*) as generations, SUM(generations.price_nano) as charged_nano, SUM(generations.provider_cost_nano) as provider_nano')
            ->groupByRaw('DATE(generations.completed_at), users.id, users.email')
            ->orderByRaw('DATE(generations.completed_at) desc')
            ->orderBy('users.email')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'email' => $row->email,
                'generations' => (int) $row->generations,
                'charged_nano' => (int) $row->charged_nano,
                'provider_nano' => (int) $row->provider_nano,
            ]);

        $days = $rows->groupBy('day')->map(fn ($dayRows, $day) => [
            'day' => $day,
            'generations' => $dayRows->sum('generations'),
            'charged_nano' => $dayRows->sum('charged_nano'),
            'provider_nano' => $dayRows->sum('provider_nano'),
        ]);

        [$usageByDay, $usageError] = $this->pixelforgeUsage($client, $start, $end);

        $format = fn (array $r) => [
            ...$r,
            'charged' => Money::toUsdString($r['charged_nano']),
            'provider_cost' => Money::toUsdString($r['provider_nano']),
            'profit' => Money::toUsdString($r['charged_nano'] - $r['provider_nano']),
        ];

        // Every day we OR Pixelforge recorded something, so a missing local record shows up too.
        $allDays = collect(array_keys($usageByDay))
            ->filter(fn ($day) => $usageByDay[$day]['generations'] > 0 && $day >= $start->toDateString() && $day <= $end->toDateString())
            ->merge($days->keys())
            ->unique()
            ->sortDesc();

        $dayList = $allDays->map(function (string $day) use ($days, $format, $usageByDay) {
            $d = $days[$day] ?? ['day' => $day, 'generations' => 0, 'charged_nano' => 0, 'provider_nano' => 0];
            $usage = $usageByDay[$day] ?? null;

            return $format($d) + [
                'pixelforge_cost' => $usage ? Money::toUsdString($usage['cost_nano']) : null,
                'pixelforge_generations' => $usage['generations'] ?? null,
                'matches' => $usage ? $usage['cost_nano'] === $d['provider_nano'] && $usage['generations'] === $d['generations'] : null,
            ];
        });

        $totals = [
            'generations' => $rows->sum('generations'),
            'charged_nano' => $rows->sum('charged_nano'),
            'provider_nano' => $rows->sum('provider_nano'),
        ];

        return Inertia::render('Admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $rows->map($format)->values(),
            'days' => $dayList->values(),
            'totals' => $format($totals),
            'usageError' => $usageError,
        ]);
    }

    /** @return array{0: array<string, array{cost_nano:int, generations:int}>, 1: ?string} */
    private function pixelforgeUsage(PixelforgeClient $client, Carbon $start, Carbon $end): array
    {
        if (! $client->isConfigured()) {
            return [[], 'PIXELFORGE_API_KEY is not set: cannot compare with Pixelforge usage.'];
        }

        try {
            $response = $client->usage($start->toDateString(), $end->toDateString());
        } catch (HttpClientException $e) {
            return [[], 'Pixelforge usage is unavailable right now.'];
        }

        if (! $response->successful()) {
            return [[], 'Pixelforge usage returned HTTP '.$response->status().'.'];
        }

        $byDay = [];
        foreach ($response->json('data', []) as $day) {
            $byDay[$day['date']] = [
                'cost_nano' => Money::fromUsdString((string) $day['cost_usd']),
                'generations' => (int) ($day['generations'] ?? 0),
            ];
        }

        return [$byDay, null];
    }
}
