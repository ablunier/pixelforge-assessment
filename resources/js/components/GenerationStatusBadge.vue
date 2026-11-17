<script setup lang="ts">
import { CircleCheck, CircleX, Clock, Send } from '@lucide/vue';
import type { Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types';

/** A Generation Status with its own label, icon and colour. */
defineProps<{
    status: GenerationStatus;
}>();

const looks: Record<
    GenerationStatus,
    { label: string; icon: Component | null; class: string }
> = {
    pending: {
        label: 'Submitting…',
        icon: Send,
        class: 'border-dashed text-muted-foreground',
    },
    queued: {
        label: 'Queued',
        icon: Clock,
        class: 'border-transparent bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200',
    },
    processing: {
        // Shown with a spinner instead of an icon.
        label: 'Processing',
        icon: null,
        class: 'border-transparent bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-200',
    },
    succeeded: {
        label: 'Succeeded',
        icon: CircleCheck,
        class: 'border-transparent bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200',
    },
    failed: {
        label: 'Failed',
        icon: CircleX,
        class: 'border-transparent bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200',
    },
};
</script>

<template>
    <Badge variant="outline" :class="looks[status].class">
        <component :is="looks[status].icon" v-if="looks[status].icon" />
        <Spinner v-else class="size-3" />
        {{ looks[status].label }}
    </Badge>
</template>
