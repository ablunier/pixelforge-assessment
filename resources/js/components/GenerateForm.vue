<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X as RemoveIcon } from '@lucide/vue';
import { computed } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import InputError from '@/components/InputError.vue';
import Price from '@/components/Price.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { priceRange, promptLength } from '@/lib/pricing';
import type { GenerateFormOptions, PricingData, Quality, Size } from '@/types';

const props = defineProps<{
    pricing: PricingData;
    options: GenerateFormOptions;
}>();

const sizeLabels: Record<Size, string> = {
    '1024x1024': '1024 × 1024 (square)',
    '1536x1024': '1536 × 1024 (landscape)',
    '1024x1536': '1024 × 1536 (portrait)',
};

// Bound rather than written inline: vue-tsc 3.3 mis-parses a static "https://"
// attribute on a component inside v-for.
const REFERENCE_IMAGE_EXAMPLE = 'https://example.com/style.png';

const qualityLabels: Record<Quality, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

// Live validation against the server's rules (Laravel Precognition): the
// form is validated as the user goes but not submitted yet.
const form = useForm(GenerationController.store(), {
    prompt: '',
    size: props.options.defaultSize,
    quality: props.options.defaultQuality,
    reference_images: [] as string[],
});

const characters = computed(() => promptLength(form.prompt));

const range = computed(() =>
    priceRange(
        characters.value,
        form.size,
        form.quality,
        form.reference_images.length,
        props.pricing,
    ),
);

function isHttpUrl(value: string): boolean {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

const promptError = computed((): string | undefined => {
    if (characters.value > props.options.maxPromptLength) {
        return `The prompt can be at most ${props.options.maxPromptLength.toLocaleString()} characters.`;
    }

    return undefined;
});

function referenceError(index: number): string | undefined {
    const url = form.reference_images[index].trim();

    if (url === '') {
        return form.touched(`reference_images.${index}`)
            ? 'Enter a reference image URL, or remove this one.'
            : undefined;
    }

    return isHttpUrl(url)
        ? undefined
        : `Enter a full http(s) URL, e.g. ${REFERENCE_IMAGE_EXAMPLE}.`;
}

/** Client-side errors show instantly; the server's rules have the last word. */
function errorFor(field: string, clientError?: string): string | undefined {
    return clientError ?? form.errors[field as keyof typeof form.errors];
}

function validateReference(index: number): void {
    form.touch(`reference_images.${index}`).validate({
        only: [
            'reference_images',
            ...form.reference_images.map(
                (_, i) => `reference_images.${i}` as const,
            ),
        ],
    });
}

function addReference(): void {
    if (form.reference_images.length < props.options.maxReferenceImages) {
        form.reference_images.push('');
    }
}

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);
    form.clearErrors();
    form.validate();
}

const hasClientErrors = computed(
    () =>
        promptError.value !== undefined ||
        form.reference_images.some(
            (_, index) => referenceError(index) !== undefined,
        ),
);

function validateAll(): void {
    form.touch([
        'prompt',
        'size',
        'quality',
        'reference_images',
        ...form.reference_images.map(
            (_, index) => `reference_images.${index}` as const,
        ),
    ]).validate();
}
</script>

<template>
    <form class="grid gap-6" novalidate @submit.prevent="validateAll">
        <div class="grid gap-2">
            <Label for="prompt">Prompt</Label>
            <textarea
                id="prompt"
                v-model="form.prompt"
                name="prompt"
                rows="4"
                required
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                :aria-invalid="errorFor('prompt', promptError) !== undefined"
                aria-describedby="prompt-length"
                class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                @change="form.validate('prompt')"
            ></textarea>
            <div class="flex items-start justify-between gap-4">
                <InputError :message="errorFor('prompt', promptError)" />
                <p
                    id="prompt-length"
                    class="ml-auto text-xs text-muted-foreground tabular-nums"
                    :class="{ 'text-red-600 dark:text-red-500': promptError }"
                >
                    {{ characters.toLocaleString() }} /
                    {{ options.maxPromptLength.toLocaleString() }}
                </p>
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="size">Size</Label>
                <Select
                    v-model="form.size"
                    name="size"
                    @update:model-value="form.validate('size')"
                >
                    <SelectTrigger
                        id="size"
                        class="w-full"
                        :aria-invalid="form.errors.size !== undefined"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="size in options.sizes"
                            :key="size"
                            :value="size"
                        >
                            {{ sizeLabels[size] }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.size" />
            </div>

            <div class="grid gap-2">
                <Label for="quality">Quality</Label>
                <Select
                    v-model="form.quality"
                    name="quality"
                    @update:model-value="form.validate('quality')"
                >
                    <SelectTrigger
                        id="quality"
                        class="w-full"
                        :aria-invalid="form.errors.quality !== undefined"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="quality in options.qualities"
                            :key="quality"
                            :value="quality"
                        >
                            {{ qualityLabels[quality] }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.quality" />
            </div>
        </div>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm leading-none font-medium">
                Reference images
                <span class="font-normal text-muted-foreground">
                    (optional, up to {{ options.maxReferenceImages }})
                </span>
            </legend>

            <div
                v-for="(url, index) in form.reference_images"
                :key="index"
                class="grid gap-1"
            >
                <div class="flex gap-2">
                    <Input
                        :id="`reference-image-${index}`"
                        v-model="form.reference_images[index]"
                        type="url"
                        inputmode="url"
                        :aria-label="`Reference image ${index + 1} URL`"
                        :aria-invalid="
                            errorFor(
                                `reference_images.${index}`,
                                referenceError(index),
                            ) !== undefined
                        "
                        :placeholder="REFERENCE_IMAGE_EXAMPLE"
                        @change="validateReference(index)"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remove reference image ${index + 1}`"
                        @click="removeReference(index)"
                    >
                        <RemoveIcon />
                    </Button>
                </div>
                <InputError
                    :message="
                        errorFor(
                            `reference_images.${index}`,
                            referenceError(index),
                        )
                    "
                />
            </div>

            <div>
                <Button
                    v-if="
                        form.reference_images.length <
                        options.maxReferenceImages
                    "
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addReference"
                >
                    <Plus />
                    Add reference image
                </Button>
            </div>
            <InputError :message="form.errors.reference_images" />
        </fieldset>

        <div
            class="flex flex-col gap-4 border-t pt-6 sm:flex-row sm:items-end sm:justify-between"
        >
            <div aria-live="polite">
                <p class="text-sm text-muted-foreground">Maximum Price</p>
                <p class="text-3xl font-semibold">
                    <Price :nanodollars="range.max" />
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Likely between <Price :nanodollars="range.min" /> and
                    <Price :nanodollars="range.max" />
                </p>
            </div>

            <div class="flex flex-col items-start gap-1 sm:items-end">
                <Button
                    type="submit"
                    :disabled="hasClientErrors || form.validating"
                >
                    Generate
                </Button>
                <p class="text-xs text-muted-foreground">
                    Generating is coming soon.
                </p>
            </div>
        </div>
    </form>
</template>
