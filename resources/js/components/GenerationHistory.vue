<script setup lang="ts">
import Price from '@/components/Price.vue';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import type { Generation, GenerationStatus } from '@/types';

defineProps<{
    generations: Generation[];
}>();

const statusLabels: Record<GenerationStatus, string> = {
    pending: 'Submitting…',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const statusVariants: Record<GenerationStatus, BadgeVariants['variant']> = {
    pending: 'outline',
    queued: 'secondary',
    processing: 'secondary',
    succeeded: 'default',
    failed: 'destructive',
};
</script>

<template>
    <p v-if="generations.length === 0" class="text-sm text-muted-foreground">
        Your Generations will appear here.
    </p>

    <ul v-else class="divide-y">
        <li
            v-for="generation in generations"
            :key="generation.id"
            class="grid gap-1 py-3"
        >
            <div class="flex items-start justify-between gap-4">
                <p class="line-clamp-2 text-sm">{{ generation.prompt }}</p>
                <Badge :variant="statusVariants[generation.status]">
                    {{ statusLabels[generation.status] }}
                </Badge>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ generation.size }} · {{ generation.quality }} · Maximum Price
                <Price :nanodollars="generation.maxPrice" />
                <template v-if="generation.price !== null">
                    · Price <Price :nanodollars="generation.price" />
                </template>
            </p>
            <p
                v-if="generation.error"
                class="text-xs text-red-600 dark:text-red-500"
            >
                {{ generation.error.message ?? generation.error.code }}
            </p>
        </li>
    </ul>
</template>
