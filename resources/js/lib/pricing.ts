// Client-side mirror of App\Pixelforge\Pricing, for the live estimate only.
// The server recomputes everything; money is BigInt picodollars, never floats.

export type TokenType = 'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens';

export interface PricingTable {
    rates: Record<TokenType, string>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<string, Record<string, number>>;
        reasoning: Record<string, { min: number; max: number }>;
    };
}

const SCALE = 12;

/** "12.50" USD per 1M tokens -> picodollars per token (rate * 10^6), exact. */
function ratePerTokenPicos(rate: string): bigint {
    const [int, frac = ''] = rate.split('.');
    const digits = 6;
    const padded = (frac + '0'.repeat(digits)).slice(0, digits);
    return BigInt(int) * 10n ** BigInt(digits) + BigInt(padded || '0');
}

export function providerCost(usage: Record<TokenType, number>, rates: Record<TokenType, string>): bigint {
    let total = 0n;
    for (const key of Object.keys(usage) as TokenType[]) {
        total += BigInt(usage[key]) * ratePerTokenPicos(rates[key]);
    }
    return total;
}

export function priceFor(cost: bigint, markupPercent: number): bigint {
    const n = cost * BigInt(100 + markupPercent);
    return (n + 99n) / 100n; // round up
}

export function formatUsd(picos: bigint): string {
    const negative = picos < 0n;
    const abs = negative ? -picos : picos;
    // round half up to 6 decimals
    const unit = 10n ** BigInt(SCALE - 6);
    const micro = (abs + unit / 2n) / unit;
    const int = micro / 1_000_000n;
    let frac = (micro % 1_000_000n).toString().padStart(6, '0').replace(/0+$/, '');
    frac = frac.padEnd(2, '0');
    return `${negative ? '-' : ''}$${int}.${frac}`;
}

export function estimate(table: PricingTable, markupPercent: number, prompt: string, size: string, quality: string, refs: number) {
    const t = table.tokens;
    const chars = [...prompt].length; // Unicode code points, like mb_strlen
    const text = Math.ceil(chars / t.characters_per_text_token);
    const varianceBp = Math.round(t.text_token_variance * 10000);
    const textMin = Math.floor((text * (10000 - varianceBp)) / 10000);
    const textMax = Math.ceil((text * (10000 + varianceBp)) / 10000);
    const images = refs * t.per_reference_image;
    const output = t.output[quality]?.[size] ?? 0;
    const r = t.reasoning[quality] ?? { min: 0, max: 0 };
    const mid = Math.floor((r.min + r.max) / 2);

    const scenario = (txt: number, reasoning: number) => {
        const cost = providerCost(
            { input_text_tokens: txt, input_image_tokens: images, output_tokens: output, reasoning_tokens: reasoning },
            table.rates,
        );
        return { cost, price: priceFor(cost, markupPercent) };
    };

    return {
        tokens: { text, textMin, textMax, images, output, reasoningMin: r.min, reasoningMax: r.max },
        min: scenario(textMin, r.min),
        expected: scenario(text, mid),
        max: scenario(textMax, r.max),
    };
}
