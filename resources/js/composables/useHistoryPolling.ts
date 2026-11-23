import { usePoll } from '@inertiajs/vue3';
import type { MaybeRefOrGetter } from 'vue';
import { onMounted, toValue, watch } from 'vue';

/** How often the history refreshes while any Generation isn't final. */
export const HISTORY_POLL_INTERVAL_MS = 2500;

/**
 * Reload the history props every few seconds while `active` is true (any of
 * the user's Generations isn't final), and stop as soon as it turns false.
 * Each poll returns a fresh `pollHistory`, so polling stops by itself once
 * everything is final, and restarts when a new Generation is submitted. It
 * refreshes the Balance and Available Balance too, so Charges and released
 * Holds show up without a reload.
 */
export function useHistoryPolling(active: MaybeRefOrGetter<boolean>): void {
    const { start, stop } = usePoll(
        HISTORY_POLL_INTERVAL_MS,
        {
            only: ['generations', 'pollHistory', 'availableBalance', 'balance'],
        },
        { autoStart: false },
    );

    const sync = (isActive: boolean): void => (isActive ? start() : stop());

    // Start on mount, not during setup, so server-side rendering never polls.
    onMounted(() => sync(toValue(active)));
    watch(() => toValue(active), sync);
}
