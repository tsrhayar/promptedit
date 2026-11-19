<?php

namespace App\Actions;

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the local row BEFORE calling Pixelforge (a webhook may arrive before the POST
 * returns), reserves the maximum price from the user's balance, then queues the submission.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly PricingService $pricing,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  array{prompt:string, size:string, quality:string, reference_images?:array}  $data
     *
     * @throws \App\Services\Billing\InsufficientCredits
     * @throws \Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException when pricing is unavailable
     */
    public function __invoke(User $user, array $data): Generation
    {
        $references = array_values($data['reference_images'] ?? []);

        // Never trust an estimate computed in the browser: recompute it here.
        $estimate = $this->pricing->estimate(
            $this->client->pricing(), $data['prompt'], $data['size'], $data['quality'], count($references),
        );

        $generation = DB::transaction(function () use ($user, $data, $references, $estimate) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            $generation = $lockedUser->generations()->create([
                'uuid' => (string) Str::uuid(),
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $references,
                'status' => Generation::PENDING,
                'estimated_min_nano' => $estimate['min_nano'],
                'estimated_max_nano' => $estimate['max_nano'],
            ]);

            $this->ledger->hold($lockedUser, $generation); // throws => whole transaction rolled back

            return $generation;
        });

        SubmitGenerationJob::dispatch($generation->id);

        return $generation;
    }
}
