<?php

namespace App\Services\Pricing;

/**
 * Turns Pixelforge token counts into money.
 *
 * - Rates are "USD per 1M tokens" strings, e.g. "12.50" => 12_500 nanodollars per token.
 * - Provider cost = sum(tokens * rate). User price = cost + markup, rounded UP to the next
 *   nanodollar (with today's rates the result is always exact; rounding is only a guard).
 */
final class PricingService
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly int $markupPercent = 25) {}

    public function markupPercent(): int
    {
        return $this->markupPercent;
    }

    /** "12.50" ($ per 1M tokens) -> 12500 (nano $ per token). Exact, no float. */
    public static function nanoPerToken(string $ratePerMillion): int
    {
        // $/1M tokens * 1e9 nano/$ / 1e6 tokens = * 1000
        return Money::decimalToInt($ratePerMillion, 3);
    }

    /** Provider cost in nanodollars for the given token usage and rates. */
    public function providerCostNano(array $usage, array $rates): int
    {
        $total = 0;
        foreach (self::TOKEN_TYPES as $type) {
            $tokens = (int) ($usage[$type] ?? 0);
            if ($tokens === 0) {
                continue;
            }
            $total += $tokens * self::nanoPerToken((string) $rates[$type]);
        }

        return $total;
    }

    /** User price = cost * (100 + markup) / 100, rounded up to the nanodollar. The one rounding rule. */
    public function withMarkup(int $costNano): int
    {
        $numerator = $costNano * (100 + $this->markupPercent);

        return intdiv($numerator + 99, 100);
    }

    /**
     * Estimate before submitting: a [min, max] range of the USER price.
     * Exact up front: output tokens (quality x size) and image tokens (765 per reference).
     * Only known afterwards: text tokens (+-15% of chars/4) and reasoning tokens ([min, max] per quality).
     * The max is a real ceiling, so it is what we reserve from the user's balance.
     *
     * @param  array  $pricing  the body of GET /v1/pricing
     * @return array{min_nano:int, max_nano:int, tokens:array}
     */
    public function estimate(array $pricing, string $prompt, string $size, string $quality, int $referenceCount): array
    {
        $t = $pricing['tokens'];

        $chars = mb_strlen($prompt, 'UTF-8'); // Unicode characters, not bytes
        $textEstimate = (int) ceil($chars / (int) $t['characters_per_text_token']);
        // Variance is a token-count factor (0.15), not money; work in basis points to stay integer.
        $varianceBp = (int) round(((float) $t['text_token_variance']) * 10_000);
        $textMin = intdiv($textEstimate * (10_000 - $varianceBp), 10_000);
        $textMax = intdiv($textEstimate * (10_000 + $varianceBp) + 9_999, 10_000);

        $fixed = [
            'input_image_tokens' => $referenceCount * (int) $t['per_reference_image'],
            'output_tokens' => (int) $t['output'][$quality][$size],
        ];

        $min = $fixed + ['input_text_tokens' => $textMin, 'reasoning_tokens' => (int) $t['reasoning'][$quality]['min']];
        $max = $fixed + ['input_text_tokens' => $textMax, 'reasoning_tokens' => (int) $t['reasoning'][$quality]['max']];

        return [
            'min_nano' => $this->withMarkup($this->providerCostNano($min, $pricing['rates'])),
            'max_nano' => $this->withMarkup($this->providerCostNano($max, $pricing['rates'])),
            'tokens' => ['min' => $min, 'max' => $max],
        ];
    }

    /**
     * Final price from a Pixelforge generation object. Uses the rates frozen ON the generation
     * (not today's pricing). Anything other than succeeded costs nothing.
     *
     * @return array{provider_cost_nano:int, price_nano:int}
     */
    public function actual(array $generation): array
    {
        if (($generation['status'] ?? null) !== 'succeeded') {
            return ['provider_cost_nano' => 0, 'price_nano' => 0];
        }

        $cost = $this->providerCostNano($generation['usage'] ?? [], $generation['rates'] ?? []);

        return ['provider_cost_nano' => $cost, 'price_nano' => $this->withMarkup($cost)];
    }
}
