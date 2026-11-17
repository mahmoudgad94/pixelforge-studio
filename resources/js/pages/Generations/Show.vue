<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { downloadFile, formatDuration } from '@/lib/download';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Check, ChevronLeft, CircleAlert, Copy, Download, ImageOff, LoaderCircle, Minus, RotateCcw, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type Status = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';
interface Step {
    key: string;
    label: string;
    state: 'done' | 'current' | 'upcoming' | 'skipped' | 'failed';
    at: string | null;
    ms: number | null;
}

const props = defineProps<{
    generation: {
        id: number;
        status: Status;
        final: boolean;
        prompt: string;
        size: string;
        quality: string;
        reference_images: string[];
        pixelforge_id: string | null;
        image_url: string | null;
        image_expired: boolean;
        can_download: boolean;
        estimated_price: string;
        estimated_price_exact: string;
        estimated_range: [string, string];
        price: string | null;
        price_exact: string | null;
        error: string | null;
        failure_kind: 'blocked' | 'limit' | 'not_sent' | 'failed' | null;
        retries: { count: number; text: string | null };
        steps: Step[];
        total_ms: number | null;
        created_at: string;
        completed_at: string | null;
    };
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'History', href: '/history' },
    { title: `Image #${props.generation.id}`, href: `/history/${props.generation.id}` },
]);

// Same wording as the History cards.
const statusText: Record<Status, string> = { pending: 'Sending', queued: 'Queued', processing: 'Generating', succeeded: 'Succeeded', failed: 'Failed' };
const statusStyle: Record<Status, string> = {
    pending: 'bg-secondary text-secondary-foreground',
    queued: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    processing: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    succeeded: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-300',
};
const failureTitle = { blocked: 'Blocked by the content policy', limit: 'Daily limit reached', not_sent: 'Could not be sent', failed: 'Generation failed' };

// Live updates: GenerationWatcher in the layout polls and reloads this page's props when the status changes.
// Here we only tick a clock so the running step and the total keep counting.
const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;
onMounted(() => (ticker = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(ticker));

const elapsed = (iso: string | null) => (iso ? Math.max(0, now.value - new Date(iso).getTime()) : null);
const stepDuration = (s: Step) => (s.state === 'current' ? elapsed(s.at) : s.ms);
const total = computed(() => (props.generation.final ? props.generation.total_ms : elapsed(props.generation.created_at)));
const fullTime = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'medium' });
const broken = ref(false);

const copied = ref(false);
async function copyId() {
    if (!props.generation.pixelforge_id) return;
    try {
        await navigator.clipboard.writeText(props.generation.pixelforge_id);
    } catch {
        const t = document.createElement('textarea');
        t.value = props.generation.pixelforge_id;
        document.body.appendChild(t);
        t.select();
        document.execCommand('copy');
        t.remove();
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <Head :title="`Image #${generation.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6" data-testid="generation-detail" :data-status="generation.status">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Button as-child size="sm" variant="outline">
                    <Link :href="route('generations.index')" data-testid="back-to-history"><ChevronLeft class="size-4" /> Back to History</Link>
                </Button>
                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="generation.can_download" size="sm" as-child>
                        <a :href="route('generations.download', generation.id)" @click="downloadFile($event, route('generations.download', generation.id))">
                            <Download class="size-4" /> Download
                        </a>
                    </Button>
                    <Button v-if="generation.status === 'failed'" size="sm" variant="outline" as-child>
                        <Link :href="route('generations.create', { retry: generation.id })"><RotateCcw class="size-4" /> Retry</Link>
                    </Button>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                <!-- The image, large. -->
                <Card class="overflow-hidden self-start">
                    <div class="flex aspect-square items-center justify-center bg-muted">
                        <img
                            v-if="generation.image_url && !broken"
                            :src="generation.image_url"
                            :alt="generation.prompt"
                            class="h-full w-full object-contain"
                            @error="broken = true"
                        />
                        <div v-else-if="generation.status === 'succeeded'" class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground">
                            <ImageOff class="size-8" /> Image link expired. Pixelforge keeps images for 24 hours.
                        </div>
                        <div v-else-if="generation.status === 'failed'" class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground">
                            <CircleAlert class="size-8" /> No image
                        </div>
                        <div v-else class="flex flex-col items-center gap-2 text-sm text-muted-foreground">
                            <LoaderCircle class="size-8 animate-spin" /> {{ statusText[generation.status] }}…
                        </div>
                    </div>
                </Card>

                <div class="min-w-0 space-y-4">
                    <Card>
                        <CardHeader class="pb-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <CardDescription>Prompt</CardDescription>
                                <Badge class="border-transparent" :class="statusStyle[generation.status]" data-testid="detail-status">{{
                                    statusText[generation.status]
                                }}</Badge>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <h1 class="whitespace-pre-wrap break-words text-base font-medium">{{ generation.prompt }}</h1>
                            <div
                                v-if="generation.error"
                                class="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm"
                                data-testid="failure-reason"
                            >
                                <div class="font-medium text-destructive">{{ failureTitle[generation.failure_kind ?? 'failed'] }}</div>
                                <p class="mt-0.5 break-words text-muted-foreground">{{ generation.error }}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="pb-2"><CardTitle class="text-base">Your settings</CardTitle></CardHeader>
                        <CardContent class="space-y-3">
                            <dl class="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt class="text-xs text-muted-foreground">Quality</dt>
                                    <dd class="font-medium capitalize" data-testid="detail-quality">{{ generation.quality }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">Size</dt>
                                    <dd class="font-medium" data-testid="detail-size">{{ generation.size }}</dd>
                                </div>
                            </dl>
                            <div>
                                <div class="text-xs text-muted-foreground">Reference images</div>
                                <ul v-if="generation.reference_images.length" class="mt-1 space-y-1">
                                    <li v-for="(url, i) in generation.reference_images" :key="url" class="flex items-center gap-2">
                                        <a :href="url" target="_blank" rel="noopener noreferrer" class="shrink-0">
                                            <img :src="url" :alt="`Reference ${i + 1}`" class="size-10 rounded border object-cover" loading="lazy" />
                                        </a>
                                        <a
                                            :href="url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="min-w-0 break-all text-xs text-muted-foreground underline-offset-2 hover:underline"
                                            >{{ url }}</a
                                        >
                                    </li>
                                </ul>
                                <p v-else class="text-sm">None</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="pb-2"><CardTitle class="text-base">Price</CardTitle></CardHeader>
                        <CardContent>
                            <dl class="grid grid-cols-2 gap-3 text-sm">
                                <div class="min-w-0">
                                    <dt class="text-xs text-muted-foreground">Estimated</dt>
                                    <dd class="font-medium tabular-nums" :title="'$' + generation.estimated_price_exact" data-testid="detail-estimate">
                                        {{ generation.estimated_price }}
                                    </dd>
                                    <dd class="text-xs tabular-nums text-muted-foreground">
                                        {{ generation.estimated_range[0] }} to {{ generation.estimated_range[1] }}
                                    </dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs text-muted-foreground">Final charge</dt>
                                    <dd v-if="generation.price" class="font-medium tabular-nums" data-testid="detail-charge" :data-exact="generation.price_exact">
                                        {{ generation.price }}
                                    </dd>
                                    <dd v-else class="text-muted-foreground">pending</dd>
                                    <dd v-if="generation.price_exact" class="break-all font-mono text-[11px] text-muted-foreground">${{ generation.price_exact }}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Card>
                <CardHeader>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <CardTitle class="text-base">What happened</CardTitle>
                        <div class="text-sm text-muted-foreground">
                            Total <span class="font-medium tabular-nums text-foreground" data-testid="detail-total">{{ formatDuration(total) }}</span>
                            <span v-if="!generation.final"> so far</span>
                        </div>
                    </div>
                    <CardDescription v-if="generation.retries.text" data-testid="detail-retries">{{ generation.retries.text }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <ol class="space-y-0" data-testid="timeline">
                        <li
                            v-for="(s, i) in generation.steps"
                            :key="s.key"
                            class="relative flex gap-3 pb-5 last:pb-0"
                            :data-step="s.key"
                            :data-state="s.state"
                        >
                            <span
                                v-if="i < generation.steps.length - 1"
                                class="absolute left-[13px] top-7 h-[calc(100%-1.75rem)] w-px bg-border"
                                aria-hidden="true"
                            />
                            <span
                                class="z-10 flex size-7 shrink-0 items-center justify-center rounded-full border"
                                :class="{
                                    'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-300': s.state === 'done',
                                    'border-transparent bg-blue-500/15 text-blue-700 dark:text-blue-300': s.state === 'current',
                                    'border-transparent bg-red-500/15 text-red-700 dark:text-red-300': s.state === 'failed',
                                    'bg-background text-muted-foreground': s.state === 'upcoming' || s.state === 'skipped',
                                }"
                            >
                                <LoaderCircle v-if="s.state === 'current'" class="size-4 animate-spin" />
                                <Check v-else-if="s.state === 'done'" class="size-4" />
                                <X v-else-if="s.state === 'failed'" class="size-4" />
                                <Minus v-else class="size-4" />
                            </span>
                            <div class="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium" :class="s.state === 'upcoming' || s.state === 'skipped' ? 'text-muted-foreground' : ''">
                                        {{ s.label }}
                                        <span v-if="s.state === 'skipped'" class="text-xs font-normal">(not reached)</span>
                                    </div>
                                    <div v-if="s.at && s.state !== 'upcoming'" class="text-xs tabular-nums text-muted-foreground">{{ fullTime(s.at) }}</div>
                                </div>
                                <div v-if="stepDuration(s) !== null && stepDuration(s) !== undefined" class="text-xs tabular-nums text-muted-foreground">
                                    <span v-if="s.state === 'current'">for </span>{{ formatDuration(stepDuration(s)) }}
                                </div>
                            </div>
                        </li>
                    </ol>
                </CardContent>
            </Card>

            <Card v-if="generation.pixelforge_id">
                <CardContent class="flex flex-wrap items-center justify-between gap-3 py-4">
                    <div class="min-w-0">
                        <div class="text-xs text-muted-foreground">Pixelforge generation id</div>
                        <div class="break-all font-mono text-sm" data-testid="pixelforge-id">{{ generation.pixelforge_id }}</div>
                    </div>
                    <Button size="sm" variant="outline" @click="copyId" data-testid="copy-id">
                        <Check v-if="copied" class="size-4" /><Copy v-else class="size-4" /> {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
