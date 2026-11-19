<?php

namespace App\Http\Controllers;

use App\Services\Billing\CreditLedger;
use App\Services\Pricing\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Simulated "Buy credits" (no payment provider): adds a fixed pack to the balance through the ledger. */
class CreditController extends Controller
{
    /** Allowed credit packs in whole US dollars. */
    public const PACKS_USD = [5, 10, 25];

    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'integer', Rule::in(self::PACKS_USD)],
        ]);

        $usd = (int) ($validated['amount'] ?? self::PACKS_USD[0]);
        $ledger->purchase($request->user(), $usd * Money::NANO_PER_USD);

        return back()->with('success', sprintf('Added $%d.00 of credits (simulated payment).', $usd));
    }
}