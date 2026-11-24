<script setup lang="ts">
import { ImagePlus } from '@lucide/vue';
import GenerationRow from '@/components/GenerationRow.vue';
import TextLink from '@/components/TextLink.vue';
import { useHistoryPolling } from '@/composables/useHistoryPolling';
import { dashboard } from '@/routes';
import type { Generation } from '@/types';

const props = defineProps<{
    /** The user's newest Generations, newest first. */
    generations: Generation[];
    /** Whether any of the user's Generations isn't final yet. */
    poll: boolean;
}>();

useHistoryPolling(() => props.poll);
</script>

<template>
    <div
        v-if="generations.length === 0"
        class="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground"
    >
        <ImagePlus class="size-8" aria-hidden="true" />
        <p>No Generations yet.</p>
        <p>
            Describe an image on the
            <TextLink :href="dashboard()">Generate</TextLink> page: it will
            appear here.
        </p>
    </div>

    <ul v-else class="divide-y">
        <GenerationRow
            v-for="generation in generations"
            :key="generation.id"
            :generation="generation"
        />
    </ul>
</template>
