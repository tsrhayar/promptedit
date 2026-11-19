<?php

namespace Tests\Unit;

use App\Services\Pricing\Money;
use App\Services\Pricing\PricingService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\PixelforgeFixtures;

class PricingServiceTest extends TestCase
{
    use PixelforgeFixtures;

    public function test_rates_convert_to_exact_nanodollars_per_token(): void
    {
        $this->assertSame(5_000, PricingService::nanoPerToken('5.00'));
        $this->assertSame(12_500, PricingService::nanoPerToken('12.50'));
        $this->assertSame(1_000, PricingService::nanoPerToken('1'));
        $this->assertSame(1, PricingService::nanoPerToken('0.001'));
    }

    public function test_a_rate_finer_than_a_nanodollar_is_refused_instead_of_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PricingService::nanoPerToken('0.0001');
    }

    public function test_documented_max_cost_example_is_exact(): void
    {
        // Docs: high, 1536x1024, 400-char prompt, 2 reference images => $0.373875000
        $usage = ['input_text_tokens' => 115, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 8000];
        $pricing = new PricingService(25);

        $cost = $pricing->providerCostNano($usage, self::rates());

        $this->assertSame(373_875_000, $cost);
        $this->assertSame('0.373875000', Money::toUsdString($cost));
        $this->assertSame(467_343_750, $pricing->withMarkup($cost)); // +25%
    }

    public function test_documented_usage_example_matches_cost_usd(): void
    {
        $usage = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950];

        $cost = (new PricingService)->providerCostNano($usage, self::rates());

        $this->assertSame(Money::fromUsdString('0.169080000'), $cost);
    }

    public function test_markup_rounds_up_to_the_next_nanodollar(): void
    {
        $pricing = new PricingService(25);

        $this->assertSame(2, $pricing->withMarkup(1));   // 1.25 -> 2
        $this->assertSame(5, $pricing->withMarkup(4));   // exact
        $this->assertSame(0, $pricing->withMarkup(0));
    }

    public function test_actual_price_uses_the_generations_frozen_rates(): void
    {
        $object = [
            'status' => 'succeeded',
            'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 0],
            'rates' => ['input_text_tokens' => '10.00', 'input_image_tokens' => '10.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '12.50'],
        ];

        $actual = (new PricingService(25))->actual($object);

        $this->assertSame(10 * 10_000 + 1100 * 80_000, $actual['provider_cost_nano']);
        $this->assertSame(110_125_000, $actual['price_nano']);
    }

    public function test_failed_generations_cost_nothing(): void
    {
        $failed = ['status' => 'failed', 'usage' => null, 'rates' => self::rates()];

        $this->assertSame(['provider_cost_nano' => 0, 'price_nano' => 0], (new PricingService)->actual($failed));
    }

    public function test_estimate_range_for_a_simple_prompt(): void
    {
        // 40 chars => 10 text tokens estimated, 8..12 with +-15%; medium 1024x1024 => 1100 output, 200..2000 reasoning
        $estimate = (new PricingService(25))->estimate($this->pricingFixture(), str_repeat('a', 40), '1024x1024', 'medium', 0);

        $this->assertSame(8, $estimate['tokens']['min']['input_text_tokens']);
        $this->assertSame(12, $estimate['tokens']['max']['input_text_tokens']);
        $this->assertSame(58_175_000, $estimate['min_nano']);  // 46_540_000 * 1.25
        $this->assertSame(86_325_000, $estimate['max_nano']);  // 69_060_000 * 1.25
    }

    public function test_estimate_counts_unicode_characters_not_bytes(): void
    {
        $estimate = (new PricingService)->estimate($this->pricingFixture(), str_repeat('é', 8), '1024x1024', 'low', 0);

        $this->assertSame(3, $estimate['tokens']['max']['input_text_tokens']); // ceil(8/4)=2, ceil(2*1.15)=3
    }

    public function test_reference_images_are_765_tokens_each(): void
    {
        $estimate = (new PricingService)->estimate($this->pricingFixture(), 'x', '1536x1024', 'high', 4);

        $this->assertSame(3060, $estimate['tokens']['max']['input_image_tokens']);
        $this->assertSame(6450, $estimate['tokens']['max']['output_tokens']);
    }

    public function test_money_formatting_is_exact(): void
    {
        $this->assertSame('0.046125000', Money::toUsdString(46_125_000));
        $this->assertSame('5.000000000', Money::toUsdString(5_000_000_000));
        $this->assertSame('-0.000000001', Money::toUsdString(-1));
        $this->assertSame(46_125_000, Money::fromUsdString('0.046125000'));
    }
}
