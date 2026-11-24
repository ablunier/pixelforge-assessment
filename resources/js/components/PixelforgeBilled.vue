<script setup lang="ts">
import { computed } from 'vue';
import Price from '@/components/Price.vue';
import { formatExact } from '@/lib/money';
import type { ReportCostComparison } from '@/types';

/**
 * What Pixelforge billed, with the signed difference from the Provider Cost
 * when they don't match to the nanodollar.
 */
const props = defineProps<{
    comparison: ReportCostComparison;
}>();

const difference = computed(() =>
    props.comparison.costDifference === null
        ? null
        : BigInt(props.comparison.costDifference),
);
</script>

<template>
    <span
        v-if="comparison.pixelforgeBilled === null"
        class="text-muted-foreground"
        title="Pixelforge's figure isn't available"
        >—</span
    >
    <span v-else class="inline-flex flex-col items-end">
        <Price :nanodollars="comparison.pixelforgeBilled" />
        <span
            v-if="difference !== null && difference !== 0n"
            class="text-xs font-medium text-amber-700 tabular-nums dark:text-amber-400"
            data-test="cost-difference"
        >
            {{ difference > 0n ? '+' : '-' }}${{
                formatExact(difference > 0n ? difference : -difference)
            }}
        </span>
    </span>
</template>
