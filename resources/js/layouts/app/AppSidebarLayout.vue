<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import AuthorBanner from '@/components/AuthorBanner.vue';
import FlashToast from '@/components/FlashToast.vue';
import GenerationWatcher from '@/components/GenerationWatcher.vue';
import PageSkeleton from '@/components/PageSkeleton.vue';
import { network } from '@/lib/network';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar">
            <AuthorBanner class="rounded-t-xl" />
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <!-- While another page loads, its skeleton replaces the content; the sidebar and header stay put. -->
            <PageSkeleton v-if="network.navigatingTo" :path="network.navigatingTo" />
            <div v-show="!network.navigatingTo" class="contents"><slot /></div>
        </AppContent>
        <FlashToast />
        <GenerationWatcher />
    </AppShell>
</template>
