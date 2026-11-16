<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Money is kept as integer picodollars (1 USD = 10^12 picodollars).
 *
 * Pixelforge prices to 9 decimal places (nanodollars) and we add a 25% markup,
 * which needs two more places. Picodollars keep every provider cost and every
 * user price exact as an integer, with no floats anywhere. A 64-bit integer
 * still holds over 9 million dollars, far above any balance here.
 */
final class Money
{
    public const SCALE = 12;

    public static function fromUsd(string|int $usd): int
    {
        return BigDecimal::of((string) $usd)
            ->withPointMovedRight(self::SCALE)
            ->toScale(0, RoundingMode::UNNECESSARY)
            ->toInt();
    }

    public static function toUsdString(int $picos, int $decimals = self::SCALE): string
    {
        return (string) BigDecimal::ofUnscaledValue($picos, self::SCALE)->toScale($decimals, RoundingMode::HALF_UP);
    }

    /** Human display: at least 2 decimals, up to 6, trailing zeros trimmed. */
    public static function format(?int $picos): ?string
    {
        if ($picos === null) {
            return null;
        }

        $value = BigDecimal::ofUnscaledValue($picos, self::SCALE)->toScale(6, RoundingMode::HALF_UP);
        $string = rtrim(rtrim((string) $value->abs(), '0'), '.');
        [$int, $frac] = array_pad(explode('.', $string, 2), 2, '');
        $frac = str_pad($frac, 2, '0');

        return ($picos < 0 ? '-' : '').'$'.$int.'.'.$frac;
    }
}
