<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 20 20"
        fill="currentColor"
        :class="className"
        v-bind="$attrs"
    >
        <rect x="0" y="0" width="9" height="9" rx="1.5" />
        <rect x="11" y="0" width="9" height="9" rx="1.5" fill-opacity="0.6" />
        <rect x="0" y="11" width="9" height="9" rx="1.5" fill-opacity="0.6" />
        <rect x="11" y="11" width="9" height="9" rx="1.5" fill-opacity="0.3" />
    </svg>
</template>
