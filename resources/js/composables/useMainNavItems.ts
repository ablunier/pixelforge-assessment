import { usePage } from '@inertiajs/vue3';
import { ChartColumn, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import { generate } from '@/routes';
import { report as adminReport } from '@/routes/admin';
import type { NavItem } from '@/types';

/** The primary navigation shared by the sidebar and header layouts. */
export function useMainNavItems() {
    const page = usePage();

    return computed<NavItem[]>(() => [
        { title: 'Generate', href: generate(), icon: Sparkles },
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
