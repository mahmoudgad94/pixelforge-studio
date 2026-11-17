<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import { computed } from 'vue';

/** The shape of the page being opened, so the skeleton matches what is about to appear. */
const props = defineProps<{ path: string }>();

const kind = computed(() => {
    const p = props.path;
    if (p.startsWith('/generate')) return 'generate';
    if (p.startsWith('/history')) return 'history';
    if (p.startsWith('/admin/report')) return 'report';
    if (p.startsWith('/admin/approvals')) return 'approvals';
    if (p.startsWith('/admin/generations/')) return 'generation';
    if (p.startsWith('/settings')) return 'settings';
    return 'generic';
});
</script>

<template>
    <div class="flex flex-1 flex-col gap-4 p-4" role="status" aria-live="polite" aria-busy="true" data-testid="page-skeleton">
        <span class="sr-only">Loading page…</span>

        <!-- Generate: form on the left, balance and price on the right. -->
        <div v-if="kind === 'generate'" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-4 rounded-xl border p-6">
                <Skeleton class="h-6 w-40" />
                <Skeleton class="h-4 w-72 max-w-full" />
                <Skeleton class="h-28 w-full" />
                <div class="flex gap-2"><Skeleton v-for="i in 3" :key="i" class="h-9 w-28" /></div>
                <div class="flex gap-2"><Skeleton v-for="i in 3" :key="i" class="h-9 w-20" /></div>
                <Skeleton class="h-10 w-full" />
            </div>
            <div class="space-y-4">
                <div class="space-y-3 rounded-xl border p-6">
                    <Skeleton class="h-4 w-20" /><Skeleton class="h-8 w-36" /><Skeleton class="h-9 w-full" />
                </div>
                <div class="space-y-3 rounded-xl border p-6">
                    <Skeleton class="h-4 w-28" /><Skeleton class="h-8 w-32" />
                    <Skeleton v-for="i in 5" :key="i" class="h-3 w-full" />
                </div>
            </div>
        </div>

        <!-- History: a summary bar and a grid of image cards. -->
        <template v-else-if="kind === 'history'">
            <Skeleton class="h-20 w-full rounded-xl" />
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="i in 6" :key="i" class="overflow-hidden rounded-xl border">
                    <Skeleton class="aspect-square w-full rounded-none" />
                    <div class="space-y-3 p-4"><Skeleton class="h-4 w-4/5" /><Skeleton class="h-4 w-1/2" /><Skeleton class="h-10 w-full" /></div>
                </div>
            </div>
        </template>

        <!-- Report / approvals: date or stat row, then tables. -->
        <template v-else-if="kind === 'report' || kind === 'approvals'">
            <Skeleton v-if="kind === 'report'" class="h-16 w-full rounded-xl" />
            <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                <div v-for="i in 4" :key="i" class="space-y-2 rounded-xl border p-4"><Skeleton class="h-4 w-24" /><Skeleton class="h-7 w-28" /></div>
            </div>
            <div v-for="t in kind === 'report' ? 2 : 1" :key="t" class="space-y-3 rounded-xl border p-4">
                <Skeleton class="h-5 w-32" />
                <Skeleton v-for="i in 6" :key="i" class="h-8 w-full" />
            </div>
            <div v-if="kind === 'approvals'" class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <Skeleton class="h-64 w-full rounded-xl" />
                <Skeleton class="h-64 w-full rounded-xl" />
            </div>
        </template>

        <!-- One generation: header, four facts, timeline table. -->
        <template v-else-if="kind === 'generation'">
            <Skeleton class="h-7 w-48" /><Skeleton class="h-4 w-80 max-w-full" />
            <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4"><Skeleton v-for="i in 4" :key="i" class="h-28 rounded-xl" /></div>
            <Skeleton class="h-24 w-full rounded-xl" />
            <div class="space-y-2 rounded-xl border p-4"><Skeleton v-for="i in 6" :key="i" class="h-8 w-full" /></div>
        </template>

        <!-- Settings: a side menu and a form. -->
        <div v-else-if="kind === 'settings'" class="flex flex-col gap-6 lg:flex-row">
            <div class="w-48 space-y-2"><Skeleton v-for="i in 3" :key="i" class="h-8 w-full" /></div>
            <div class="flex-1 space-y-4">
                <Skeleton class="h-6 w-40" /><Skeleton class="h-4 w-64 max-w-full" />
                <div v-for="i in 3" :key="i" class="space-y-2"><Skeleton class="h-4 w-24" /><Skeleton class="h-9 w-full max-w-md" /></div>
            </div>
        </div>

        <template v-else>
            <Skeleton class="h-7 w-48" />
            <Skeleton class="h-40 w-full rounded-xl" />
            <Skeleton class="h-40 w-full rounded-xl" />
        </template>
    </div>
</template>
