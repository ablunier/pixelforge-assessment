<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div
        :class="
            cn(
                'flex aspect-square size-8 items-center justify-center rounded-md bg-primary text-primary-foreground',
                props.class,
            )
        "
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 20 20"
            fill="currentColor"
            class="size-[62.5%]"
            aria-hidden="true"
        >
            <rect x="0" y="0" width="9" height="9" rx="1.5" />
            <rect
                x="11"
                y="0"
                width="9"
                height="9"
                rx="1.5"
                fill-opacity="0.6"
            />
            <rect
                x="0"
                y="11"
                width="9"
                height="9"
                rx="1.5"
                fill-opacity="0.6"
            />
            <rect
                x="11"
                y="11"
                width="9"
                height="9"
                rx="1.5"
                fill-opacity="0.3"
            />
        </svg>
    </div>
</template>
