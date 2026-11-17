<?php

namespace Tests\Unit;

use App\Pixelforge\Pricing;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    private function pricing(): Pricing
    {
        return new Pricing(Pricing::defaultTable(), 25);
    }

    public function test_docs_worked_example_max_cost(): void
    {
        // Docs: high, 1536x1024, 400 chars, 2 reference images, max reasoning = 0.373875000
        $cost = Pricing::providerCost([
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ], Pricing::defaultTable()['rates']);

        $this->assertSame('0.373875000', Money::toUsdString($cost, 9));
    }

    public function test_estimate_max_matches_docs_example(): void
    {
        $estimate = $this->pricing()->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame('0.373875000', Money::toUsdString($estimate['max']['cost'], 9));
        $this->assertSame(115, $estimate['tokens']['max']['input_text_tokens']);
        $this->assertSame(85, $estimate['tokens']['min']['input_text_tokens']);
    }

    public function test_docs_succeeded_example_cost_and_markup_are_exact(): void
    {
        // usage from the docs' succeeded example
        $cost = Pricing::providerCost([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
        ], Pricing::defaultTable()['rates']);

        // 12*5 + 765*10 + 6450*40 + 3184*12.5 = 60 + 7650 + 258000 + 39800 = 305510 per 1M -> 0.305510
        $this->assertSame('0.305510000', Money::toUsdString($cost, 9));
        $this->assertSame('0.381887500000', Money::toUsdString($this->pricing()->priceFor($cost)));
    }

    public function test_markup_rounds_up_never_down(): void
    {
        $this->assertSame(2, $this->pricing()->priceFor(1)); // 1.25 -> 2
        $this->assertSame(125, $this->pricing()->priceFor(100));
    }

    public function test_text_tokens_count_unicode_characters(): void
    {
        $this->assertSame(2, $this->pricing()->estimatedTextTokens('مرحبا')); // 5 characters, not 10 bytes -> ceil(5/4)
        $this->assertSame(1, $this->pricing()->estimatedTextTokens('🎨🎨'));
    }

    public function test_odd_rates_are_exact(): void
    {
        $cost = Pricing::providerCost(['output_tokens' => 1], ['output_tokens' => '0.000001']);
        $this->assertSame(1, $cost); // 1e-12 USD
    }

    public function test_markup_outside_0_to_1000_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pricing(Pricing::defaultTable(), -5);
    }

    public function test_a_saved_markup_overrides_the_current_setting(): void
    {
        $cost = Money::fromUsd('0.056560');
        $this->assertSame('0.070700000000', Money::toUsdString($this->pricing()->priceFor($cost)));
        $this->assertSame('0.056560000000', Money::toUsdString($this->pricing()->priceFor($cost, 0)));
        $this->assertSame('0.084840000000', Money::toUsdString($this->pricing()->priceFor($cost, 50)));
    }
}
