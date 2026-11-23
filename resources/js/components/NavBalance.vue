<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import Price from '@/components/Price.vue';
import { show } from '@/routes/credits';

/** The signed-in user's Balance, linking to Buy credits. */
const page = usePage();
const balance = computed(() => page.props.balance);
</script>

<template>
    <div
        v-if="balance !== null"
        class="flex items-center gap-2 px-2 text-sm"
        data-test="nav-balance"
    >
        <Link
            :href="show()"
            class="flex items-center gap-2 text-muted-foreground hover:text-foreground"
        >
            <Wallet class="size-4" aria-hidden="true" />
            <span>Balance</span>
        </Link>
        <Price :nanodollars="balance" />
    </div>
</template>
