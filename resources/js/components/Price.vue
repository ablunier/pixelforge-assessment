<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatExact, formatForDisplay } from '@/lib/money';

/** A dollar amount shown to 4 decimals, with the exact 9-decimal value on hover. */
const props = defineProps<{
    nanodollars: bigint | number;
}>();

const amount = computed(() => BigInt(props.nanodollars));
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    tabindex="0"
                    class="cursor-help tabular-nums underline decoration-muted-foreground/50 decoration-dotted underline-offset-4"
                    >${{ formatForDisplay(amount) }}</span
                >
            </TooltipTrigger>
            <TooltipContent>
                <span class="tabular-nums">${{ formatExact(amount) }}</span>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
