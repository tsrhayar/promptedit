// Mirrors app/Services/Pricing/PricingService.php for the LIVE estimate only.
// The server recomputes the estimate on submit; the browser never decides a price.
// All amounts are integer nanodollars (max ~0.6 USD = 6e8, far below Number.MAX_SAFE_INTEGER).

export type Size = '1024x1024' | '1536x1024' | '1024x1536';
export type Quality = 'low' | 'medium' | 'high';

export interface Pricing {
    rates: Record<string, string>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<Quality, Record<Size, number>>;
        reasoning: Record<Quality, { min: number; max: number }>;
    };
}

export type TokenCounts = Record<'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens', number>;

/** "12.50" ($ per 1M tokens) -> 12500 nanodollars per token, parsed as a decimal string (no float). */
export function nanoPerToken(rate: string): number {
    const [whole, fraction = ''] = rate.trim().split('.');
    return Number(whole) * 1000 + Number((fraction + '000').slice(0, 3));
}

export function providerCostNano(tokens: TokenCounts, rates: Record<string, string>): number {
    return (Object.keys(tokens) as (keyof TokenCounts)[]).reduce((sum, type) => sum + tokens[type] * nanoPerToken(rates[type]), 0);
}

export function withMarkup(costNano: number, markupPercent: number): number {
    return Math.floor((costNano * (100 + markupPercent) + 99) / 100);
}

export function estimate(pricing: Pricing, markupPercent: number, prompt: string, size: Size, quality: Quality, referenceCount: number) {
    const t = pricing.tokens;
    const chars = [...prompt].length; // Unicode code points, like mb_strlen
    const textEstimate = Math.ceil(chars / t.characters_per_text_token);
    const varianceBp = Math.round(t.text_token_variance * 10_000);

    const fixed = {
        input_image_tokens: referenceCount * t.per_reference_image,
        output_tokens: t.output[quality][size],
    };
    const min: TokenCounts = {
        ...fixed,
        input_text_tokens: Math.floor((textEstimate * (10_000 - varianceBp)) / 10_000),
        reasoning_tokens: t.reasoning[quality].min,
    };
    const max: TokenCounts = {
        ...fixed,
        input_text_tokens: Math.floor((textEstimate * (10_000 + varianceBp) + 9_999) / 10_000),
        reasoning_tokens: t.reasoning[quality].max,
    };

    return {
        minNano: withMarkup(providerCostNano(min, pricing.rates), markupPercent),
        maxNano: withMarkup(providerCostNano(max, pricing.rates), markupPercent),
        tokens: { min, max },
    };
}

/** Display only: nanodollars -> "$0.086325" (6 decimals) */
export function formatNano(nano: number, decimals = 6): string {
    const negative = nano < 0;
    const abs = Math.abs(nano);
    const whole = Math.floor(abs / 1e9);
    const fraction = String(abs % 1e9).padStart(9, '0').slice(0, decimals);
    return `${negative ? '-' : ''}$${whole}.${fraction}`;
}

/** Display only: exact decimal string "0.070700000" -> "$0.070700" (truncated, never re-computed). */
export function formatUsd(value: string | null, decimals = 6): string {
    if (value === null) return '—';
    const negative = value.startsWith('-');
    const [whole, fraction = ''] = value.replace('-', '').split('.');
    return `${negative ? '-' : ''}$${whole}.${(fraction + '000000000').slice(0, decimals)}`;
}
