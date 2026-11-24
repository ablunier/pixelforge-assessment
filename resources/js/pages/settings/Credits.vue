<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CreditsController from '@/actions/App/Http/Controllers/CreditsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Price from '@/components/Price.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { formatForDisplay } from '@/lib/money';
import { generate } from '@/routes';
import { show } from '@/routes/credits';
import { edit as editProfile } from '@/routes/profile';
import type {
    CreditPack,
    LedgerEntry,
    LedgerEntryKind,
    LedgerPage,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Settings',
                href: editProfile(),
            },
            {
                title: 'Buy credits',
                href: show(),
            },
        ],
    },
});

defineProps<{
    packs: CreditPack[];
    /** One page of the user's ledger entries, newest first. */
    ledgerEntries: LedgerEntry[];
    ledgerPage: LedgerPage;
}>();

const page = usePage();
const balance = computed(() => page.props.balance);

const form = useForm(CreditsController.store(), { pack: '' });

function buy(pack: CreditPack): void {
    form.pack = pack.id;
    form.submit({ preserveScroll: true });
}

/** Each kind in plain language, with what it did to the user's Credits. */
const kindLabels: Record<LedgerEntryKind, string> = {
    top_up: 'Top-up: Credits added',
    hold: 'Hold: set aside while a Generation runs',
    hold_release: 'Hold release: set-aside Credits returned',
    charge: 'Charge: the Price of a succeeded Generation',
};

/** Where a ledger entry's Generation shows in the history on the main page. */
function generationHref(generationId: string): string {
    return `${generate().url}#generation-${generationId}`;
}

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

    <h1 class="sr-only">Buy credits</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Buy credits"
            description="Credits are added at once. This is a demo: no payment is taken."
        />
        <p v-if="balance !== null" class="text-sm">
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
                    formatForDisplay(BigInt(pack.amount)).replace(/\.0+$/, '')
                }}
            </Button>
        </div>
        <InputError :message="form.errors.pack" />
    </div>

    <div class="space-y-6">
        <Heading
            variant="small"
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
                <div class="flex min-w-0 flex-col">
                    <span class="font-medium">{{
                        kindLabels[entry.kind]
                    }}</span>
                    <TextLink
                        v-if="entry.generation !== null"
                        :href="generationHref(entry.generation.id)"
                        class="truncate"
                        :title="entry.generation.prompt"
                        >{{ entry.generation.prompt }}</TextLink
                    >
                    <time
                        :datetime="entry.createdAt"
                        class="text-muted-foreground"
                        >{{ formatDate(entry.createdAt) }}</time
                    >
                </div>
                <span class="shrink-0 tabular-nums"
                    >{{ sign(entry.kind) }} <Price :nanodollars="entry.amount"
                /></span>
            </li>
        </ul>
        <nav
            v-if="ledgerPage.last > 1"
            aria-label="History pages"
            class="flex items-center justify-between text-sm"
        >
            <Button
                v-if="ledgerPage.current > 1"
                variant="outline"
                size="sm"
                as-child
            >
                <Link
                    :href="show({ query: { page: ledgerPage.current - 1 } })"
                    preserve-scroll
                    >Newer</Link
                >
            </Button>
            <span v-else />
            <span class="text-muted-foreground"
                >Page {{ ledgerPage.current }} of {{ ledgerPage.last }}</span
            >
            <Button
                v-if="ledgerPage.current < ledgerPage.last"
                variant="outline"
                size="sm"
                as-child
            >
                <Link
                    :href="show({ query: { page: ledgerPage.current + 1 } })"
                    preserve-scroll
                    >Older</Link
                >
            </Button>
            <span v-else />
        </nav>
    </div>
</template>
