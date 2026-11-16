<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Generation {
    id: number;
    status: 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';
    prompt: string;
    size: string;
    quality: string;
    estimated_price: string;
    estimated_range: [string, string];
    price: string | null;
    price_exact: string | null;
    image_url: string | null;
    image_expired: boolean;
    error: string | null;
    created_at: string;
    completed_at: string | null;
}

const props = defineProps<{
    generations: { data: Generation[]; links: { url: string | null; label: string; active: boolean }[] };
    balance: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/history' }];
const flash = computed(() => (usePage().props as any).flash?.status as string | undefined);

const inFlight = computed(() => props.generations.data.some((g) => !['succeeded', 'failed'].includes(g.status)));
const brokenImages = ref(new Set<number>());

let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (inFlight.value && document.visibilityState === 'visible') {
            router.reload({ only: ['generations', 'balance'] });
        }
    }, 2500);
});
onBeforeUnmount(() => clearInterval(timer));

const badge: Record<Generation['status'], string> = {
    pending: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    queued: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    succeeded: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
};

const label = (s: Generation['status']) => (s === 'pending' ? 'sending' : s);
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-4 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold">History</h1>
                <div class="text-sm text-muted-foreground">Balance <span class="font-medium text-foreground">{{ balance }}</span></div>
            </div>

            <div v-if="generations.data.length === 0" class="rounded-xl border p-8 text-center text-sm text-muted-foreground">
                No generations yet. <Link :href="route('generations.create')" class="underline">Create one</Link>.
            </div>

            <div v-for="g in generations.data" :key="g.id" class="grid gap-4 rounded-xl border p-4 sm:grid-cols-[160px_1fr]">
                <div class="flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-muted text-center text-xs text-muted-foreground">
                    <img
                        v-if="g.image_url && !brokenImages.has(g.id)"
                        :src="g.image_url"
                        :alt="g.prompt"
                        class="h-full w-full object-cover"
                        loading="lazy"
                        @error="brokenImages.add(g.id)"
                    />
                    <span v-else-if="g.status === 'succeeded'" class="p-2">Image link expired (Pixelforge keeps images for 24 hours)</span>
                    <span v-else-if="g.status === 'failed'" class="p-2">No image</span>
                    <span v-else class="animate-pulse p-2">Working…</span>
                </div>
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="badge[g.status]">{{ label(g.status) }}</span>
                        <span class="text-xs text-muted-foreground">{{ g.size }} · {{ g.quality }} · {{ new Date(g.created_at).toLocaleString() }}</span>
                    </div>
                    <p class="line-clamp-3 text-sm">{{ g.prompt }}</p>
                    <dl class="grid grid-cols-2 gap-2 text-sm sm:max-w-md">
                        <div>
                            <dt class="text-xs text-muted-foreground">Estimated</dt>
                            <dd>{{ g.estimated_price }} <span class="text-xs text-muted-foreground">({{ g.estimated_range[0] }} to {{ g.estimated_range[1] }})</span></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Actual</dt>
                            <dd v-if="g.status === 'succeeded'" :title="'$' + g.price_exact">{{ g.price }}</dd>
                            <dd v-else-if="g.status === 'failed'">$0.00 (not charged)</dd>
                            <dd v-else class="text-muted-foreground">pending</dd>
                        </div>
                    </dl>
                    <p v-if="g.error" class="text-sm text-red-600">{{ g.error }}</p>
                </div>
            </div>

            <nav v-if="generations.links.length > 3" class="flex flex-wrap gap-1">
                <Link
                    v-for="l in generations.links"
                    :key="l.label"
                    :href="l.url ?? ''"
                    class="rounded border px-3 py-1 text-sm"
                    :class="[l.active ? 'bg-primary text-primary-foreground' : '', !l.url ? 'pointer-events-none opacity-40' : '']"
                    v-html="l.label"
                />
            </nav>
        </div>
    </AppLayout>
</template>
