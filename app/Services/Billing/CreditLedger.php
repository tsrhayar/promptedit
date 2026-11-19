<?php

namespace App\Services\Billing;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pricing\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Append-only ledger of credits (fake money, no payment provider).
 * users.balance_nano is a cache of SUM(amount_nano), always updated in the same transaction.
 *
 * Flow per generation: hold(max estimate) on submit, then either
 *   succeeded -> release(hold) + charge(actual price)   (price <= max is guaranteed by the estimate)
 *   failed / rejected -> release(hold) only.
 * unique(generation_id, type) makes a second charge impossible, even with a bug upstream.
 */
class CreditLedger
{
    public const PURCHASE_AMOUNT_NANO = 5 * Money::NANO_PER_USD; // "Buy $5 of credits"

    public function purchase(User $user, int $amountNano = self::PURCHASE_AMOUNT_NANO): void
    {
        DB::transaction(function () use ($user, $amountNano) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->record($locked, null, CreditTransaction::PURCHASE, $amountNano);
        });
    }

    /**
     * Reserve the maximum possible price. Must be called inside a transaction.
     *
     * @throws InsufficientCredits
     */
    public function hold(User $lockedUser, Generation $generation): void
    {
        $amount = $generation->estimated_max_nano;
        if ($lockedUser->balance_nano < $amount) {
            throw new InsufficientCredits($amount, $lockedUser->balance_nano);
        }

        $this->record($lockedUser, $generation->id, CreditTransaction::HOLD, -$amount);
    }

    /** Called by the state machine (inside its transaction) when a generation becomes final. */
    public function settle(Generation $generation): void
    {
        $hold = CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransaction::HOLD)
            ->first();

        if (! $hold) {
            return; // nothing reserved (e.g. created before balances existed)
        }

        $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);

        $alreadySettled = CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransaction::RELEASE)
            ->exists();
        if ($alreadySettled) {
            return;
        }

        $this->record($user, $generation->id, CreditTransaction::RELEASE, -$hold->amount_nano);

        if ($generation->status === Generation::SUCCEEDED && $generation->price_nano > 0) {
            if ($generation->price_nano > -$hold->amount_nano) {
                Log::warning('Generation price exceeded the reserved maximum', ['generation' => $generation->id]);
            }
            $this->record($user, $generation->id, CreditTransaction::CHARGE, -$generation->price_nano);
        }
    }

    private function record(User $lockedUser, ?int $generationId, string $type, int $amountNano): void
    {
        CreditTransaction::create([
            'user_id' => $lockedUser->id,
            'generation_id' => $generationId,
            'type' => $type,
            'amount_nano' => $amountNano,
        ]);

        $lockedUser->balance_nano += $amountNano;
        $lockedUser->save();
    }
}
