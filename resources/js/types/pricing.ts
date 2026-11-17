export type Size = '1024x1024' | '1536x1024' | '1024x1536';

export type Quality = 'low' | 'medium' | 'high';

/** Integer nanodollars per token, keyed like Pixelforge's rates. */
export type Rates = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
};

/** The token rules from App\Pricing\Pricing::tokenRules(). */
export type TokenRules = {
    characters_per_text_token: number;
    text_token_variance_percent: number;
    per_reference_image: number;
    output: Record<Quality, Record<Size, number>>;
    reasoning: Record<Quality, { min: number; max: number }>;
};

export type PricingData = {
    rates: Rates;
    tokens: TokenRules;
};

/** Lowest and highest Price, in integer nanodollars. */
export type PriceRange = {
    min: number;
    max: number;
};

export type GenerateFormOptions = {
    sizes: Size[];
    qualities: Quality[];
    defaultSize: Size;
    defaultQuality: Quality;
    maxPromptLength: number;
    maxReferenceImages: number;
};
