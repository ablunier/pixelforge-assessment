<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ImageIcon, RotateCcw } from '@lucide/vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import Price from '@/components/Price.vue';
import { Button } from '@/components/ui/button';
import type { Generation } from '@/types';

/**
 * One Generation in the history. Later features plug in through the slots:
 * `thumbnail` (the image), `outcome` (how a final Generation ended) and
 * `actions` ("Try again" on failed Generations by default), each receiving
 * the Generation.
 */
defineProps<{
    generation: Generation;
}>();
</script>

<template>
    <li class="flex gap-4 py-3">
        <div
            class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted text-muted-foreground"
        >
            <slot name="thumbnail" :generation="generation">
                <ImageIcon class="size-5" aria-hidden="true" />
            </slot>
        </div>

        <div class="grid min-w-0 flex-1 content-start gap-1">
            <div class="flex items-start justify-between gap-4">
                <p
                    class="line-clamp-2 text-sm break-words"
                    :title="generation.prompt"
                >
                    {{ generation.prompt }}
                </p>
                <GenerationStatusBadge :status="generation.status" />
            </div>

            <p class="text-xs text-muted-foreground">
                {{ generation.size }} · {{ generation.quality }} · Maximum Price
                <Price :nanodollars="generation.maxPrice" />
            </p>

            <slot name="outcome" :generation="generation">
                <p v-if="generation.price !== null" class="text-sm">
                    Price
                    <Price :nanodollars="generation.price" />
                </p>
                <p
                    v-else-if="generation.error"
                    class="text-xs text-red-600 dark:text-red-500"
                >
                    {{ generation.error.message ?? generation.error.code }}
                </p>
            </slot>

            <div
                v-if="$slots.actions || generation.status === 'failed'"
                class="flex gap-2 pt-1"
            >
                <slot name="actions" :generation="generation">
                    <Form
                        v-bind="GenerationController.tryAgain.form(generation)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            :disabled="processing"
                        >
                            <RotateCcw aria-hidden="true" />
                            Try again
                        </Button>
                    </Form>
                </slot>
            </div>
        </div>
    </li>
</template>
