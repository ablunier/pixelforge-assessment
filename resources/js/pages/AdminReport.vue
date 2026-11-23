<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Price from '@/components/Price.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { report } from '@/routes/admin';
import type {
    ReportDay,
    ReportFigures,
    ReportRange,
    ReportUser,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Admin report',
                href: report(),
            },
        ],
    },
});

const props = defineProps<{
    range: ReportRange;
    /** One row per UTC day in the range, oldest first, including days without activity. */
    days: ReportDay[];
    /** One row per user with activity in the range, biggest spenders first. */
    users: ReportUser[];
    totals: ReportFigures;
}>();

const form = useForm(report(), {
    from: props.range.from,
    to: props.range.to,
});

function showRange(): void {
    form.submit({ preserveScroll: true });
}

const figureColumns: { key: keyof ReportFigures; label: string }[] = [
    { key: 'charges', label: 'Charges' },
    { key: 'providerCost', label: 'Provider Cost' },
    { key: 'profit', label: 'Profit' },
];

function profitClass(profit: number): string {
    return cn(profit < 0 && 'text-red-600 dark:text-red-400');
}
</script>

<template>
    <Head title="Admin report" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <section
            class="w-full rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
        >
            <Heading
                title="Admin report"
                :description="`Succeeded Generations, counted on the UTC day they completed. Up to ${range.maxDays} days at a time.`"
            />
            <form
                class="flex flex-wrap items-end gap-4"
                @submit.prevent="showRange"
            >
                <div class="grid gap-2">
                    <Label for="from">From (UTC)</Label>
                    <Input id="from" v-model="form.from" type="date" required />
                    <InputError :message="form.errors.from" />
                </div>
                <div class="grid gap-2">
                    <Label for="to">To (UTC)</Label>
                    <Input id="to" v-model="form.to" type="date" required />
                    <InputError :message="form.errors.to" />
                </div>
                <Button type="submit" :disabled="form.processing">Show</Button>
            </form>
        </section>

        <section
            class="w-full rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
        >
            <Heading
                title="Daily"
                :description="`${range.from} to ${range.to}`"
            />
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-test="report-days">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="py-2 pr-4 font-medium">Day (UTC)</th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Generations
                            </th>
                            <th
                                v-for="column in figureColumns"
                                :key="column.key"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="day in days" :key="day.date">
                            <td class="py-2 pr-4 tabular-nums">
                                {{ day.date }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ day.generations }}
                            </td>
                            <td
                                v-for="column in figureColumns"
                                :key="column.key"
                                :class="
                                    cn(
                                        'py-2 pr-4 text-right',
                                        column.key === 'profit' &&
                                            profitClass(day.profit),
                                    )
                                "
                            >
                                <Price :nanodollars="day[column.key]" />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 font-medium">
                            <td class="py-2 pr-4">Total</td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ totals.generations }}
                            </td>
                            <td
                                v-for="column in figureColumns"
                                :key="column.key"
                                :class="
                                    cn(
                                        'py-2 pr-4 text-right',
                                        column.key === 'profit' &&
                                            profitClass(totals.profit),
                                    )
                                "
                            >
                                <Price :nanodollars="totals[column.key]" />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section
            class="w-full rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
        >
            <Heading
                title="Per user"
                description="Each user with succeeded Generations in the range, biggest spenders first."
            />
            <p
                v-if="users.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                No succeeded Generations in this range.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-test="report-users">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="py-2 pr-4 font-medium">User</th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Generations
                            </th>
                            <th
                                v-for="column in figureColumns"
                                :key="column.key"
                                class="py-2 pr-4 text-right font-medium"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="user in users" :key="user.userId">
                            <td class="py-2 pr-4">
                                <div class="font-medium">{{ user.name }}</div>
                                <div class="text-muted-foreground">
                                    {{ user.email }}
                                </div>
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ user.generations }}
                            </td>
                            <td
                                v-for="column in figureColumns"
                                :key="column.key"
                                :class="
                                    cn(
                                        'py-2 pr-4 text-right',
                                        column.key === 'profit' &&
                                            profitClass(user.profit),
                                    )
                                "
                            >
                                <Price :nanodollars="user[column.key]" />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 font-medium">
                            <td class="py-2 pr-4">Total</td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ totals.generations }}
                            </td>
                            <td
                                v-for="column in figureColumns"
                                :key="column.key"
                                :class="
                                    cn(
                                        'py-2 pr-4 text-right',
                                        column.key === 'profit' &&
                                            profitClass(totals.profit),
                                    )
                                "
                            >
                                <Price :nanodollars="totals[column.key]" />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </div>
</template>
