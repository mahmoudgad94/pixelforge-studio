<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight, ChevronLeft } from 'lucide-vue-next';

interface Activity {
    id: number;
    at: string;
    channel: 'api' | 'webhook';
    action: string;
    status_code: number | null;
    outcome: string;
    error_code: string | null;
    duration_ms: number | null;
    attempt: number | null;
    event_id: string | null;
    ip: string | null;
    detail: string | null;
}

const props = defineProps<{
    generation: {
        id: number;
        pixelforge_id: string | null;
        user: string | null;
        prompt: string;
        size: string;
        quality: string;
        status: string;
        error: string | null;
        usage: Record<string, number> | null;
        estimate: string;
        estimate_range: [string, string];
        cost: string;
        price: string;
        profit: string;
        submit_attempts: number;
        created_at: string;
        completed_at: string | null;
    };
    stages: { label: string; ms: number | null }[];
    summary: { api_calls: number; webhooks: number; duplicates_ignored: number; sender_ips: string[] };
    activity: Activity[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin report', href: '/admin/report' },
    { title: `Generation #${props.generation.id}`, href: `/admin/generations/${props.generation.id}` },
];
const day = props.generation.completed_at?.slice(0, 10);
const secs = (ms: number | null) => (ms === null ? '–' : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`);
const good = ['ok', 'replayed', 'processed', 'ping'];
const outcomeClass = (o: string) =>
    good.includes(o)
        ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
        : o === 'duplicate' || o === 'retryable_error'
          ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
          : 'bg-red-500/15 text-red-700 dark:text-red-300';
const time = (iso: string) => iso.slice(11, 23);
</script>

<template>
    <Head :title="`Generation #${generation.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold tracking-tight">Generation #{{ generation.id }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ generation.user }} · {{ generation.size }} · <span class="capitalize">{{ generation.quality }}</span>
                        <span v-if="generation.pixelforge_id" class="font-mono"> · {{ generation.pixelforge_id }}</span>
                    </p>
                    <p class="mt-2 max-w-3xl text-sm">{{ generation.prompt }}</p>
                </div>
                <Button v-if="day" as-child size="sm" variant="outline">
                    <Link :href="route('admin.report', { start: day, end: day })"><ChevronLeft class="size-4" /> Back to {{ day }}</Link>
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="pb-2"><CardDescription>Status</CardDescription></CardHeader>
                    <CardContent>
                        <div class="text-2xl font-semibold capitalize">{{ generation.status }}</div>
                        <p v-if="generation.error" class="mt-1 text-xs text-destructive">{{ generation.error }}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"><CardDescription>Estimated, then charged</CardDescription></CardHeader>
                    <CardContent>
                        <div class="text-2xl font-semibold tabular-nums">{{ generation.price }}</div>
                        <p class="text-xs text-muted-foreground tabular-nums">estimate {{ generation.estimate }} ({{ generation.estimate_range[0] }} to {{ generation.estimate_range[1] }})</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"><CardDescription>Pixelforge cost and our profit</CardDescription></CardHeader>
                    <CardContent>
                        <div class="font-mono text-lg">${{ generation.cost }}</div>
                        <p class="text-xs text-muted-foreground tabular-nums">profit {{ generation.profit }}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2"><CardDescription>Conversation with Pixelforge</CardDescription></CardHeader>
                    <CardContent class="space-y-0.5 text-sm">
                        <div>{{ summary.api_calls }} API calls, {{ generation.submit_attempts }} submit attempt{{ generation.submit_attempts === 1 ? '' : 's' }}</div>
                        <div>{{ summary.webhooks }} webhooks, {{ summary.duplicates_ignored }} duplicate{{ summary.duplicates_ignored === 1 ? '' : 's' }} ignored</div>
                        <div class="font-mono text-xs text-muted-foreground">{{ summary.sender_ips.join(', ') || 'no webhook IPs yet' }}</div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">How long each stage took</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="(s, i) in stages" :key="s.label" class="rounded-lg border p-3" :class="i === stages.length - 1 ? 'bg-muted/50' : ''">
                        <div class="text-xs text-muted-foreground">{{ s.label }}</div>
                        <div class="mt-1 text-xl font-semibold tabular-nums">{{ secs(s.ms) }}</div>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="generation.usage">
                <CardHeader><CardTitle class="text-base">Tokens billed by Pixelforge</CardTitle></CardHeader>
                <CardContent class="flex flex-wrap gap-6 font-mono text-sm">
                    <div v-for="(v, k) in generation.usage" :key="k">
                        <div class="text-xs text-muted-foreground">{{ k }}</div>
                        <div>{{ v }}</div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Every call, in order (UTC)</CardTitle>
                    <CardDescription>
                        <ArrowUpRight class="inline size-3.5" /> we called Pixelforge.
                        <ArrowDownLeft class="inline size-3.5" /> Pixelforge called our webhook: IP, delivery attempt and what we answered.
                    </CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Time</TableHead><TableHead>Call</TableHead><TableHead>Result</TableHead>
                                <TableHead class="text-right">HTTP</TableHead><TableHead class="text-right">Took</TableHead>
                                <TableHead>Attempt</TableHead><TableHead>From / event</TableHead><TableHead>Detail</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="a in activity" :key="a.id">
                                <TableCell class="font-mono text-xs">{{ time(a.at) }}</TableCell>
                                <TableCell class="whitespace-nowrap text-sm">
                                    <ArrowUpRight v-if="a.channel === 'api'" class="mr-1 inline size-3.5 text-blue-600" />
                                    <ArrowDownLeft v-else class="mr-1 inline size-3.5 text-violet-600" />
                                    <span class="font-mono text-xs">{{ a.action }}</span>
                                </TableCell>
                                <TableCell>
                                    <Badge class="border-transparent" :class="outcomeClass(a.outcome)">{{ a.outcome.replace('_', ' ') }}</Badge>
                                    <div v-if="a.error_code" class="mt-1 font-mono text-[11px] text-muted-foreground">{{ a.error_code }}</div>
                                </TableCell>
                                <TableCell class="text-right font-mono text-xs">{{ a.status_code ?? '–' }}</TableCell>
                                <TableCell class="text-right tabular-nums text-xs">{{ secs(a.duration_ms) }}</TableCell>
                                <TableCell class="text-xs">{{ a.attempt ?? '–' }}</TableCell>
                                <TableCell class="font-mono text-[11px]">
                                    <div v-if="a.ip">{{ a.ip }}</div>
                                    <div v-if="a.event_id" class="text-muted-foreground">{{ a.event_id }}</div>
                                </TableCell>
                                <TableCell class="max-w-xs whitespace-normal text-xs text-muted-foreground">{{ a.detail }}</TableCell>
                            </TableRow>
                            <TableRow v-if="activity.length === 0">
                                <TableCell :colspan="8" class="py-10 text-center text-muted-foreground">No calls logged for this generation (it may predate logging).</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
