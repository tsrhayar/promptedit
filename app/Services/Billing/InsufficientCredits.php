<?php

namespace App\Services\Billing;

use App\Services\Pricing\Money;
use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $neededNano, public readonly int $balanceNano)
    {
        parent::__construct(sprintf(
            'Not enough credits: this generation can cost up to $%s and your balance is $%s.',
            Money::toUsdString($neededNano),
            Money::toUsdString($balanceNano),
        ));
    }
}
