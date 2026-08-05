<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BarChart3, FileText, MessageSquare } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as chatIndex } from '@/routes/chat';
import { index as documentsIndex } from '@/routes/documents';
import { index as evaluationIndex } from '@/routes/evaluation';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Documents',
        href: documentsIndex(),
        icon: FileText,
    },
    {
        title: 'Chat',
        href: chatIndex(),
        icon: MessageSquare,
    },
    {
        title: 'Evaluation',
        href: evaluationIndex(),
        icon: BarChart3,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="documentsIndex()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>
    </Sidebar>
    <slot />
</template>
