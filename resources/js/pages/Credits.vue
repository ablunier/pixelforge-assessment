<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CreditsController from '@/actions/App/Http/Controllers/CreditsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Price from '@/components/Price.vue';
import { Button } from '@/components/ui/button';
import { formatForDisplay } from '@/lib/money';
import { show } from '@/routes/credits';
import type { CreditPack, LedgerEntry, LedgerEntryKind } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Buy credits',
                href: show(),
            },
        ],
    },
});

defineProps<{
    packs: CreditPack[];
    /** The user's ledger entries, newest first. */
    ledgerEntries: LedgerEntry[];
}>();

const page = usePage();
const balance = computed(() => page.props.balance);

const form = useForm(CreditsController.store(), { pack: '' });

function buy(pack: CreditPack): void {
    form.pack = pack.id;
    form.submit({ preserveScroll: true });
}

const kindLabels: Record<LedgerEntryKind, string> = {
    top_up: 'Top-up',
    hold: 'Hold',
    hold_release: 'Hold release',
    charge: 'Charge',
};

/** Whether the entry adds to (+) or takes from (−) what the user can spend. */
function sign(kind: LedgerEntryKind): string {
    return kind === 'top_up' || kind === 'hold_release' ? '+' : '−';
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head title="Buy credits" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <section
            class="mx-auto w-full max-w-3xl rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
        >
            <Heading
                title="Buy credits"
                description="Credits are added at once. This is a demo: no payment is taken."
            />
            <p v-if="balance !== null" class="mb-4 text-sm">
                Balance: <Price :nanodollars="balance" />
            </p>
            <div class="flex flex-wrap gap-3">
                <Button
                    v-for="pack in packs"
                    :key="pack.id"
                    :disabled="form.processing"
                    @click="buy(pack)"
                >
                    Buy ${{
                        formatForDisplay(BigInt(pack.amount)).replace(
                            /\.0+$/,
                            '',
                        )
                    }}
                </Button>
            </div>
            <InputError class="mt-2" :message="form.errors.pack" />
        </section>

        <section
            class="mx-auto w-full max-w-3xl rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
        >
            <Heading
                title="History"
                description="Your Top-ups, Holds and Charges, newest first."
            />
            <p
                v-if="ledgerEntries.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                Nothing yet.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="entry in ledgerEntries"
                    :key="entry.id"
                    class="flex items-center justify-between gap-4 py-3 text-sm"
                >
                    <div class="flex flex-col">
                        <span class="font-medium">{{
                            kindLabels[entry.kind]
                        }}</span>
                        <time
                            :datetime="entry.createdAt"
                            class="text-muted-foreground"
                            >{{ formatDate(entry.createdAt) }}</time
                        >
                    </div>
                    <span class="tabular-nums"
                        >{{ sign(entry.kind) }}
                        <Price :nanodollars="entry.amount"
                    /></span>
                </li>
            </ul>
        </section>
    </div>
</template>
