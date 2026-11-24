import { usePage } from '@inertiajs/vue3';
import { ChartColumn, History, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import { generate } from '@/routes';
import { report as adminReport } from '@/routes/admin';
import { index as history } from '@/routes/generations';
import type { NavItem } from '@/types';

/** The primary navigation shared by the sidebar and header layouts. */
export function useMainNavItems() {
    const page = usePage();

    return computed<NavItem[]>(() => [
        { title: 'Generate', href: generate(), icon: Sparkles },
        { title: 'History', href: history(), icon: History },
        ...(page.props.can.viewAdminReport
            ? [
                  {
                      title: 'Admin report',
                      href: adminReport(),
                      icon: ChartColumn,
                  },
              ]
            : []),
    ]);
}
