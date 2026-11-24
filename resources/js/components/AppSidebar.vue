<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, History, LayoutGrid } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavBalance from '@/components/NavBalance.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { report as adminReport } from '@/routes/admin';
import { index as history } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Generate',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'History',
        href: history(),
        icon: History,
    },
    ...(page.props.can.viewAdminReport
        ? [{ title: 'Admin report', href: adminReport(), icon: ChartColumn }]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavBalance class="group-data-[collapsible=icon]:hidden" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
