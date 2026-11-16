<?php

namespace App\Pixelforge;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Pixelforge pricing maths. Everything is integer picodollars.
 *
 * Provider cost = sum(tokens x rate per 1M tokens). The API never returns a
 * price, so the real cost is computed from the generation's `usage` and the
 * `rates` frozen on that generation when it was created.
 *
 * User price = provider cost + markup (25%), rounded UP to the next picodollar
 * so we can never undercharge. With Pixelforge's current rates the result is
 * already exact and no rounding happens at all.
 */
final class Pricing
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array{rates: array<string,string>, tokens: array<string,mixed>}  $table  shape of GET /v1/pricing
     */
    public function __construct(private array $table, private int $markupPercent = 25) {}

    public static function defaultTable(): array
    {
        return [
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    public function table(): array
    {
        return $this->table;
    }

    public function markupPercent(): int
    {
        return $this->markupPercent;
    }

    /** Provider cost in picodollars for an exact token usage and a set of rates (USD per 1M tokens). */
    public static function providerCost(array $usage, array $rates): int
    {
        $total = BigDecimal::zero();

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = (int) ($usage[$type] ?? 0);
            if ($tokens === 0) {
                continue;
            }
            if (! array_key_exists($type, $rates)) {
                throw new \UnexpectedValueException("Missing rate for {$type}");
            }
            // tokens * rate / 1e6 USD  ==  tokens * rate * 1e6 picodollars
            $total = $total->plus(BigDecimal::of((string) $rates[$type])->multipliedBy($tokens)->withPointMovedRight(6));
        }

        // Exact for any rate with up to 6 decimals; round up beyond that (never undercharge).
        return $total->toScale(0, RoundingMode::UP)->toInt();
    }

    public function priceFor(int $providerCostPicos): int
    {
        return BigInteger::of($providerCostPicos)
            ->multipliedBy(100 + $this->markupPercent)
            ->dividedBy(100, RoundingMode::UP)
            ->toInt();
    }

    public function estimatedTextTokens(string $prompt): int
    {
        $perToken = (int) $this->table['tokens']['characters_per_text_token'];

        return intdiv(mb_strlen($prompt, 'UTF-8') + $perToken - 1, $perToken);
    }

    /**
     * Estimate for a request, before it is sent. Returns min / expected / max
     * as both provider cost and user price. Text tokens are only known within
     * +/- variance, and reasoning tokens only within the quality's range.
     *
     * @return array{min:array{cost:int,price:int}, expected:array{cost:int,price:int}, max:array{cost:int,price:int}, tokens:array}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $tokens = $this->table['tokens'];
        $rates = $this->table['rates'];

        $text = $this->estimatedTextTokens($prompt);
        // variance as basis points to stay in integer maths (0.15 -> 1500)
        $varianceBp = (int) round(((float) $tokens['text_token_variance']) * 10000);
        $textMin = intdiv($text * (10000 - $varianceBp), 10000);
        $textMax = intdiv($text * (10000 + $varianceBp) + 9999, 10000);

        $images = $referenceImages * (int) $tokens['per_reference_image'];
        $output = (int) $tokens['output'][$quality][$size];
        $reasoning = $tokens['reasoning'][$quality];
        $reasoningMid = intdiv((int) $reasoning['min'] + (int) $reasoning['max'], 2);

        $scenarios = [
            'min' => ['input_text_tokens' => $textMin, 'input_image_tokens' => $images, 'output_tokens' => $output, 'reasoning_tokens' => (int) $reasoning['min']],
            'expected' => ['input_text_tokens' => $text, 'input_image_tokens' => $images, 'output_tokens' => $output, 'reasoning_tokens' => $reasoningMid],
            'max' => ['input_text_tokens' => $textMax, 'input_image_tokens' => $images, 'output_tokens' => $output, 'reasoning_tokens' => (int) $reasoning['max']],
        ];

        $result = ['tokens' => $scenarios];
        foreach ($scenarios as $name => $usage) {
            $cost = self::providerCost($usage, $rates);
            $result[$name] = ['cost' => $cost, 'price' => $this->priceFor($cost)];
        }

        return $result;
    }
}
