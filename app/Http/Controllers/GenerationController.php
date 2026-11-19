<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\Billing\InsufficientCredits;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\Money;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(Request $request, PixelforgeClient $client): Response
    {
        try {
            $pricing = $client->pricing();
        } catch (HttpClientException $e) {
            Log::warning('Pixelforge pricing unavailable', ['error' => $e->getMessage()]);
            $pricing = null;
        }

        return Inertia::render('Generations/Create', [
            // Sent once so the browser can show a live estimate without a request per keystroke.
            'pricing' => $pricing,
            'markupPercent' => (int) config('services.pixelforge.markup_percent'),
            'balanceNano' => $request->user()->balance_nano,
            'configured' => $client->isConfigured(),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $createGeneration($request->user(), $request->validated());
        } catch (InsufficientCredits $e) {
            return back()->withErrors(['credits' => $e->getMessage()])->withInput();
        } catch (HttpClientException) {
            return back()->withErrors(['prompt' => 'Pricing is temporarily unavailable, so we cannot estimate the cost. Please try again.'])->withInput();
        }

        return to_route('generations.index')->with('success', 'Generation submitted. Its status updates live below.');
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest('id')->limit(50)->get();

        return Inertia::render('Generations/Index', [
            'generations' => $generations->map(fn (Generation $g) => $this->present($g))->values(),
            'markupPercent' => (int) config('services.pixelforge.markup_percent'), // UI only: explains the price
        ]);
    }

    /** Retry a failed/rejected generation: a NEW generation (new uuid = new Idempotency-Key). */
    public function retry(Request $request, Generation $generation, CreateGeneration $createGeneration): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        abort_unless(in_array($generation->status, [Generation::FAILED, Generation::REJECTED], true), 422);

        try {
            $createGeneration($request->user(), $generation->only(['prompt', 'size', 'quality', 'reference_images']));
        } catch (InsufficientCredits $e) {
            return back()->with('error', $e->getMessage());
        } catch (HttpClientException) {
            return back()->with('error', 'Pricing is temporarily unavailable. Please try again.');
        }

        return back()->with('success', 'Retry submitted as a new generation.');
    }

    /** Money is sent as exact decimal strings; the browser only formats it. */
    private function present(Generation $g): array
    {
        $final = $g->isFinal();

        return [
            'id' => $g->id,
            'prompt' => $g->prompt,
            'size' => $g->size,
            'quality' => $g->quality,
            'reference_count' => count($g->reference_images ?? []),
            'status' => $g->status,
            'error_code' => $g->error_code,
            'error_message' => $g->error_message,
            'estimated_min' => Money::toUsdString($g->estimated_min_nano),
            'estimated_max' => Money::toUsdString($g->estimated_max_nano),
            'price' => $final && $g->price_nano !== null ? Money::toUsdString($g->price_nano) : null,
            'usage' => $g->usage,
            'image_url' => $g->status === Generation::SUCCEEDED ? $g->image_url : null,
            'image_expired' => $g->image_url_expires_at?->isPast() ?? false,
            'created_at' => $g->created_at?->toIso8601String(),
            'completed_at' => $g->completed_at?->toIso8601String(),
        ];
    }
}
