<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import GenerateForm from '@/components/GenerateForm.vue';
import GenerationHistory from '@/components/GenerationHistory.vue';
import Heading from '@/components/Heading.vue';
import { priceRange as mirroredPriceRange } from '@/lib/pricing';
import { dashboard } from '@/routes';
import type {
    GenerateFormOptions,
    Generation,
    PriceRange,
    PricingData,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generate',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    /** Null while Pixelforge's rates can't be had: the form can't price, so it can't submit. */
    pricing: PricingData | null;
    /** The server's Price Range for the form's defaults; null with `pricing`. */
    priceRange: PriceRange | null;
    options: GenerateFormOptions;
    /** The user's Generations, newest first. */
    generations: Generation[];
    /** The user's Available Balance in integer nanodollars. */
    availableBalance: number;
    /** Whether any of the user's Generations isn't final, so the history should poll. */
    pollHistory: boolean;
}>();

// Guard against the TypeScript mirror drifting from App\Pricing\Pricing.
if (
    import.meta.env.DEV &&
    props.pricing !== null &&
    props.priceRange !== null
) {
    const mirrored = mirroredPriceRange(
        0,
        props.options.defaultSize,
        props.options.defaultQuality,
        0,
        props.pricing,
    );

    if (
        mirrored.min !== BigInt(props.priceRange.min) ||
        mirrored.max !== BigInt(props.priceRange.max)
    ) {
        console.error(
            'The TypeScript Pricing mirror disagrees with the server',
            { mirrored, server: props.priceRange },
        );
    }
}
</script>

<template>
    <Head title="Generate" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <section
            class="mx-auto w-full max-w-3xl rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
        >
            <Heading
                title="Generate an image"
                description="Describe the image you want. You pay Pixelforge's cost plus 25%, and nothing if it fails."
            />
            <GenerateForm
                :pricing="pricing"
                :options="options"
                :available-balance="availableBalance"
            />
        </section>

        <section
            class="mx-auto w-full max-w-3xl rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
        >
            <Heading
                title="Your Generations"
                description="Newest first. Statuses update live until every Generation is final."
            />
            <GenerationHistory :generations="generations" :poll="pollHistory" />
        </section>
    </div>
</template>
