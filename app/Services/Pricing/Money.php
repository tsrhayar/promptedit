<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Money helpers. All amounts are integer nanodollars (1 USD = 1_000_000_000 nano),
 * which is the precision Pixelforge uses ("0.046125000"). No floats anywhere.
 */
final class Money
{
    public const NANO_PER_USD = 1_000_000_000;

    /** 46125000 -> "0.046125000" (exact, 9 decimals). */
    public static function toUsdString(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $abs = abs($nano);

        return $sign.intdiv($abs, self::NANO_PER_USD).'.'.str_pad((string) ($abs % self::NANO_PER_USD), 9, '0', STR_PAD_LEFT);
    }

    /** "0.169080000" -> 169080000. Rejects anything finer than a nanodollar. */
    public static function fromUsdString(string $usd): int
    {
        return self::decimalToInt($usd, 9);
    }

    /**
     * Parses an exact decimal string and multiplies it by 10^$scale, without floats.
     * Throws if the result is not an integer (precision would be lost).
     */
    public static function decimalToInt(string $decimal, int $scale): int
    {
        $decimal = trim($decimal);
        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Not a decimal number: {$decimal}");
        }

        $fraction = $m[3] ?? '';
        $significant = rtrim($fraction, '0');
        if (strlen($significant) > $scale) {
            throw new InvalidArgumentException("{$decimal} has more than {$scale} decimals");
        }

        $value = (int) $m[2] * (10 ** $scale) + (int) str_pad($significant, $scale, '0');

        return $m[1] === '-' ? -$value : $value;
    }
}
