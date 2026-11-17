<script setup lang="ts">
import { Download, ImageIcon, ImageOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { Generation } from '@/types';

/**
 * A Generation's image as a thumbnail that opens the full-size image, with a
 * download link. Shows Pixelforge's signed URL until the stored copy is ready,
 * and an "image expired" placeholder once neither is available.
 */
const props = defineProps<{
    generation: Generation;
}>();

// A provider URL can expire while the page is open: treat a broken image as expired.
const broken = ref(false);
watch(
    () => props.generation.image?.url,
    () => (broken.value = false),
);

const image = computed(() =>
    broken.value ? null : (props.generation.image?.url ?? null),
);
const downloadUrl = computed(() =>
    broken.value ? null : (props.generation.image?.downloadUrl ?? null),
);
const expired = computed(
    () =>
        props.generation.image !== null &&
        (broken.value || props.generation.image.source === 'expired'),
);
</script>

<template>
    <Dialog v-if="image !== null && downloadUrl !== null">
        <DialogTrigger as-child>
            <button
                type="button"
                class="size-full cursor-zoom-in focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Open the full-size image"
            >
                <img
                    :src="image"
                    :alt="generation.prompt"
                    class="size-full object-cover"
                    loading="lazy"
                    @error="broken = true"
                />
            </button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-3xl">
            <DialogTitle class="line-clamp-2 pr-6 text-sm font-normal">
                {{ generation.prompt }}
            </DialogTitle>
            <DialogDescription class="sr-only">
                The full-size image, {{ generation.size }}.
            </DialogDescription>
            <img
                :src="image"
                :alt="generation.prompt"
                class="max-h-[70vh] w-full rounded-md object-contain"
            />
            <div class="flex justify-end">
                <Button as-child variant="outline" size="sm">
                    <a :href="downloadUrl" download>
                        <Download aria-hidden="true" />
                        Download
                    </a>
                </Button>
            </div>
        </DialogContent>
    </Dialog>

    <div
        v-else-if="expired"
        class="flex flex-col items-center gap-0.5 text-center text-[10px] leading-tight"
        title="Image expired"
    >
        <ImageOff class="size-5" aria-hidden="true" />
        <span>Image expired</span>
    </div>

    <ImageIcon v-else class="size-5" aria-hidden="true" />
</template>
