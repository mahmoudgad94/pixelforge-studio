<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { CircleAlert, ImageOff, LoaderCircle, RotateCcw, Sparkles } from 'lucide-vue-next';
import { reactive } from 'vue';

type Status = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

interface Generation {
    id: number;
    status: Status;
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
const broken = reactive(new Set<number>());

// Live updates come from GenerationWatcher in the layout (small JSON poll, reloads this page's data only when something finishes).

const statusStyle: Record<Status, string> = {
    pending: 'bg-secondary text-secondary-foreground',
    queued: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    processing: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    succeeded: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
};
const statusLabel = (s: Status) => (s === 'pending' ? 'Sending' : s.charAt(0).toUpperCase() + s.slice(1));
const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">History</h1>
                    <p class="text-sm text-muted-foreground">Balance <span class="font-medium text-foreground tabular-nums">{{ balance }}</span></p>
                </div>
                <Button as-child><Link :href="route('generations.create')"><Sparkles class="size-4" /> New image</Link></Button>
            </div>

            <Card v-if="generations.data.length === 0">
                <CardContent class="flex flex-col items-center gap-3 py-16 text-center">
                    <Sparkles class="size-8 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">No images yet.</p>
                    <Button as-child variant="outline"><Link :href="route('generations.create')">Create your first one</Link></Button>
                </CardContent>
            </Card>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Card v-for="g in generations.data" :key="g.id" class="overflow-hidden">
                    <div class="relative flex aspect-square items-center justify-center bg-muted">
                        <img
                            v-if="g.image_url && !broken.has(g.id)"
                            :src="g.image_url"
                            :alt="g.prompt"
                            class="h-full w-full object-cover"
                            loading="lazy"
                            @error="broken.add(g.id)"
                        />
                        <div v-else-if="g.status === 'succeeded'" class="flex flex-col items-center gap-2 p-6 text-center text-xs text-muted-foreground">
                            <ImageOff class="size-6" /> Image link expired. Pixelforge keeps images for 24 hours.
                        </div>
                        <div v-else-if="g.status === 'failed'" class="flex flex-col items-center gap-2 p-6 text-center text-xs text-muted-foreground">
                            <CircleAlert class="size-6" /> No image
                        </div>
                        <div v-else class="flex flex-col items-center gap-2 text-xs text-muted-foreground">
                            <LoaderCircle class="size-6 animate-spin" /> {{ statusLabel(g.status) }}…
                        </div>
                        <Badge class="absolute left-3 top-3 border-transparent" :class="statusStyle[g.status]">{{ statusLabel(g.status) }}</Badge>
                    </div>
                    <CardContent class="space-y-3 p-4">
                        <p class="line-clamp-2 text-sm font-medium" :title="g.prompt">{{ g.prompt }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <Badge variant="outline">{{ g.size }}</Badge>
                            <Badge variant="outline" class="capitalize">{{ g.quality }}</Badge>
                        </div>
                        <dl class="grid grid-cols-2 gap-2 border-t pt-3 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">Estimated</dt>
                                <dd class="tabular-nums">{{ g.estimated_price }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Charged</dt>
                                <dd v-if="g.status === 'succeeded'" class="font-medium tabular-nums" :title="'$' + g.price_exact">{{ g.price }}</dd>
                                <dd v-else-if="g.status === 'failed'" class="tabular-nums">$0.00</dd>
                                <dd v-else class="text-muted-foreground">pending</dd>
                            </div>
                        </dl>
                        <div v-if="g.error" class="flex items-start justify-between gap-3">
                            <p class="text-xs text-destructive">{{ g.error }}</p>
                            <Button v-if="g.status === 'failed'" size="sm" variant="outline" class="shrink-0" as-child>
                                <Link :href="route('generations.create', { retry: g.id })"><RotateCcw class="size-3.5" /> Retry</Link>
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ when(g.created_at) }}</p>
                    </CardContent>
                </Card>
            </div>

            <nav v-if="generations.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <Button v-for="l in generations.links" :key="l.label" :variant="l.active ? 'default' : 'outline'" size="sm" :disabled="!l.url" as-child>
                    <Link :href="l.url ?? '#'" preserve-scroll><span v-html="l.label" /></Link>
                </Button>
            </nav>
        </div>
    </AppLayout>
</template>
