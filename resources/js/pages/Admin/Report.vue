<script setup lang="ts">
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, LoaderCircle } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    start: string;
    end: string;
    days: {
        date: string;
        generations: number;
        spend: string;
        cost: string;
        profit: string;
        pixelforge_generations: number | null;
        pixelforge_cost: string | null;
        matches: boolean | null;
    }[];
    perUser: { date: string; user: string; generations: number; spend: string; cost: string; profit: string }[];
    totals: { generations: number; spend: string; cost: string; profit: string };
    usageError: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];
const start = ref(props.start);
const end = ref(props.end);
const apply = () => router.get(route('admin.report'), { start: start.value, end: end.value }, { preserveState: true });

// Quick ranges, in UTC days like Pixelforge billing.
const utcDay = (offset: number) => new Date(Date.now() - offset * 86_400_000).toISOString().slice(0, 10);
const presets = [
    { label: 'Today', start: () => utcDay(0), end: () => utcDay(0) },
    { label: 'Yesterday', start: () => utcDay(1), end: () => utcDay(1) },
    { label: '7 days', start: () => utcDay(6), end: () => utcDay(0) },
    { label: '30 days', start: () => utcDay(29), end: () => utcDay(0) },
];
const pick = (from: string, to: string) => {
    start.value = from;
    end.value = to;
    apply();
};
const isActive = (p: (typeof presets)[number]) => props.start === p.start() && props.end === p.end();
const singleDay = computed(() => props.start === props.end);

// Days with nothing on either side are noise; hide them unless asked.
const showEmpty = ref(false);
const isEmpty = (d: (typeof props.days)[number]) => d.generations === 0 && !d.pixelforge_generations;
const visibleDays = computed(() => (showEmpty.value ? props.days : props.days.filter((d) => !isEmpty(d))));
const emptyCount = computed(() => props.days.filter(isEmpty).length);
// Open a day to see the generations inside it. Loaded on demand so long ranges stay fast.
type DayGeneration = {
    id: number;
    pixelforge_id: string | null;
    completed_at: string;
    user: string | null;
    email: string | null;
    status: 'succeeded' | 'failed';
    prompt: string;
    size: string;
    quality: string;
    references: number;
    tokens: Record<'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens', number> | null;
    estimate: string;
    cost: string;
    spend: string;
    profit: string;
    error: string | null;
    image_url: string | null;
};
const detail = reactive<Record<string, { loading: boolean; error: string | null; rows: DayGeneration[] }>>({});
const toggleDay = async (date: string) => {
    if (detail[date]) {
        delete detail[date];
        return;
    }
    detail[date] = { loading: true, error: null, rows: [] };
    try {
        const res = await fetch(route('admin.report.day', date), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        detail[date].rows = (await res.json()).generations;
    } catch (e) {
        detail[date].error = e instanceof Error ? e.message : 'Could not load this day.';
    } finally {
        detail[date].loading = false;
    }
};
if (singleDay.value && props.days[0]?.generations) toggleDay(props.days[0].date);
const tokenLabels = { input_text_tokens: 'text', input_image_tokens: 'image', output_tokens: 'output', reasoning_tokens: 'reasoning' } as const;

const stats = [
    { label: 'Generations', key: 'generations' as const, prefix: '' },
    { label: 'Credit spend', key: 'spend' as const, prefix: '' },
    { label: 'Provider cost', key: 'cost' as const, prefix: '$' },
    { label: 'Profit', key: 'profit' as const, prefix: '' },
];
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Admin report</h1>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="singleDay">Showing {{ start }} (UTC). </template>Days are the UTC day a generation completed, like Pixelforge
                        billing. Click a day to see the generations inside it.
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex rounded-md border p-0.5">
                        <Button
                            v-for="p in presets"
                            :key="p.label"
                            size="sm"
                            :variant="isActive(p) ? 'secondary' : 'ghost'"
                            class="h-8"
                            @click="pick(p.start(), p.end())"
                        >
                            {{ p.label }}
                        </Button>
                    </div>
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                        <div class="grid gap-1">
                            <Label for="start" class="text-xs">From</Label><Input id="start" v-model="start" type="date" class="h-9" />
                        </div>
                        <div class="grid gap-1">
                            <Label for="end" class="text-xs">To</Label><Input id="end" v-model="end" type="date" class="h-9" />
                        </div>
                        <Button type="submit" size="sm" class="h-9">Apply</Button>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-[repeat(2,minmax(0,1fr))] lg:grid-cols-[repeat(4,minmax(0,1fr))]">
                <Card v-for="s in stats" :key="s.key" class="min-w-0">
                    <CardHeader class="pb-2"
                        ><CardDescription>{{ s.label }}</CardDescription></CardHeader
                    >
                    <CardContent
                        ><div class="truncate text-2xl font-semibold tabular-nums" :title="s.prefix + totals[s.key]">{{ s.prefix }}{{ totals[s.key] }}</div></CardContent
                    >
                </Card>
            </div>

            <Alert v-if="usageError" variant="destructive">Could not load Pixelforge usage: {{ usageError }}</Alert>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Per day</CardTitle>
                    <CardDescription
                        >Our provider cost checked against Pixelforge GET /v1/usage. Counts and costs must match exactly.</CardDescription
                    >
                </CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Day</TableHead><TableHead class="text-right">Gens</TableHead
                                ><TableHead class="text-right">Credit spend</TableHead> <TableHead class="text-right">Provider cost</TableHead
                                ><TableHead class="text-right">Pixelforge</TableHead> <TableHead class="text-right">Profit</TableHead
                                ><TableHead>Check</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <template v-for="d in visibleDays" :key="d.date">
                                <TableRow
                                    :class="[isEmpty(d) ? 'text-muted-foreground' : '', d.generations ? 'cursor-pointer' : '']"
                                    @click="d.generations && toggleDay(d.date)"
                                >
                                    <TableCell class="font-mono text-xs">
                                        <span class="inline-flex items-center gap-1">
                                            <ChevronRight
                                                v-if="d.generations"
                                                class="size-3.5 transition-transform"
                                                :class="detail[d.date] ? 'rotate-90' : ''"
                                                aria-hidden="true"
                                            />
                                            <button
                                                type="button"
                                                class="underline-offset-4 hover:underline"
                                                :aria-expanded="!!detail[d.date]"
                                                :disabled="!d.generations"
                                                @click.stop="toggleDay(d.date)"
                                            >
                                                {{ d.date }}
                                            </button>
                                        </span>
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums">{{ d.generations }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ d.spend }}</TableCell>
                                    <TableCell class="text-right font-mono text-xs">{{ d.cost }}</TableCell>
                                    <TableCell class="text-right font-mono text-xs">{{ d.pixelforge_cost ?? '–' }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ d.profit }}</TableCell>
                                    <TableCell>
                                        <Badge
                                            v-if="d.matches === true"
                                            class="border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-300"
                                            >Match</Badge
                                        >
                                        <Badge v-else-if="d.matches === false" variant="destructive">Mismatch</Badge>
                                        <span v-else class="text-muted-foreground">–</span>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="detail[d.date]" class="hover:bg-transparent">
                                    <TableCell :colspan="7" class="whitespace-normal bg-muted/40 p-3">
                                        <div v-if="detail[d.date].loading" class="flex items-center gap-2 py-3 text-sm text-muted-foreground">
                                            <LoaderCircle class="size-4 animate-spin" /> Loading {{ d.date }}…
                                        </div>
                                        <p v-else-if="detail[d.date].error" class="py-3 text-sm text-destructive">
                                            Could not load this day: {{ detail[d.date].error }}
                                        </p>
                                        <ul v-else class="grid gap-2">
                                            <li
                                                v-for="g in detail[d.date].rows"
                                                :key="g.id"
                                                class="flex flex-col gap-3 rounded-md border bg-background p-3 sm:flex-row"
                                                :class="g.status === 'failed' ? 'opacity-75' : ''"
                                            >
                                                <a v-if="g.image_url" :href="g.image_url" target="_blank" rel="noopener" class="shrink-0">
                                                    <img :src="g.image_url" :alt="g.prompt" loading="lazy" class="size-16 rounded object-cover" />
                                                </a>
                                                <div v-else class="hidden size-16 shrink-0 rounded bg-muted sm:block" />
                                                <div class="min-w-0 flex-1 space-y-1">
                                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                                        <span class="font-mono">{{ g.completed_at }} UTC</span>
                                                        <span>·</span>
                                                        <span :title="g.email ?? ''">{{ g.user }}</span>
                                                        <span>·</span>
                                                        <span
                                                            >{{ g.size }} {{ g.quality
                                                            }}<template v-if="g.references">, {{ g.references }} ref</template></span
                                                        >
                                                        <Badge v-if="g.status === 'failed'" variant="outline" class="h-5">Failed, free</Badge>
                                                        <Link
                                                            :href="route('admin.generations.show', g.id)"
                                                            class="font-mono underline-offset-4 hover:text-foreground hover:underline"
                                                            title="Every API call and webhook for this generation"
                                                            >#{{ g.id }}<template v-if="g.pixelforge_id"> · {{ g.pixelforge_id }}</template> ·
                                                            timeline</Link
                                                        >
                                                    </div>
                                                    <p class="truncate text-sm" :title="g.prompt">{{ g.prompt }}</p>
                                                    <p v-if="g.error" class="text-xs text-destructive">{{ g.error }}</p>
                                                    <p v-if="g.tokens" class="text-xs tabular-nums text-muted-foreground">
                                                        <template v-for="(label, key, i) in tokenLabels" :key="key"
                                                            ><template v-if="i">, </template>{{ g.tokens[key] ?? 0 }} {{ label }}</template
                                                        >
                                                        tokens
                                                    </p>
                                                </div>
                                                <dl class="grid shrink-0 grid-cols-[auto_auto] gap-x-3 text-xs tabular-nums sm:text-right">
                                                    <dt class="text-muted-foreground">Estimate</dt>
                                                    <dd>{{ g.estimate }}</dd>
                                                    <dt class="text-muted-foreground">Charged</dt>
                                                    <dd class="font-medium">{{ g.spend }}</dd>
                                                    <dt class="text-muted-foreground">Cost</dt>
                                                    <dd class="font-mono">${{ g.cost }}</dd>
                                                    <dt class="text-muted-foreground">Profit</dt>
                                                    <dd>{{ g.profit }}</dd>
                                                </dl>
                                            </li>
                                            <li v-if="detail[d.date].rows.length === 0" class="py-3 text-sm text-muted-foreground">
                                                Nothing finished on this day.
                                            </li>
                                        </ul>
                                    </TableCell>
                                </TableRow>
                            </template>
                            <TableRow v-if="visibleDays.length === 0">
                                <TableCell :colspan="7" class="py-10 text-center text-muted-foreground"
                                    >No generations completed in this range.</TableCell
                                >
                            </TableRow>
                        </TableBody>
                    </Table>
                    <div v-if="emptyCount" class="border-t px-4 py-2 text-xs text-muted-foreground">
                        {{ showEmpty ? 'Showing' : 'Hiding' }} {{ emptyCount }} day{{ emptyCount === 1 ? '' : 's' }} with no generations.
                        <button type="button" class="ml-1 underline underline-offset-4 hover:text-foreground" @click="showEmpty = !showEmpty">
                            {{ showEmpty ? 'Hide them' : 'Show them' }}
                        </button>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle class="text-base">Per day and user</CardTitle></CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Day</TableHead><TableHead>User</TableHead><TableHead class="text-right">Gens</TableHead>
                                <TableHead class="text-right">Credit spend</TableHead><TableHead class="text-right">Provider cost</TableHead
                                ><TableHead class="text-right">Profit</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="r in perUser" :key="r.date + r.user">
                                <TableCell class="font-mono text-xs">{{ r.date }}</TableCell>
                                <TableCell>{{ r.user }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ r.generations }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ r.spend }}</TableCell>
                                <TableCell class="text-right font-mono text-xs">{{ r.cost }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ r.profit }}</TableCell>
                            </TableRow>
                            <TableRow v-if="perUser.length === 0">
                                <TableCell :colspan="6" class="py-10 text-center text-muted-foreground"
                                    >No completed generations in this range.</TableCell
                                >
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
