<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import GenerationHistory from '@/components/GenerationHistory.vue';
import Heading from '@/components/Heading.vue';
import { index as history } from '@/routes/generations';
import type { Generation } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'History',
                href: history(),
            },
        ],
    },
});

defineProps<{
    /** The user's Generations, newest first. */
    generations: Generation[];
    /** Whether any of the user's Generations isn't final, so the history should poll. */
    pollHistory: boolean;
}>();
</script>

<template>
    <Head title="History" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
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
