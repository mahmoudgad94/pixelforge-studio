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
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

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
                    <p class="text-sm text-muted-foreground">Days are the UTC day a generation completed, like Pixelforge billing.</p>
                </div>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                    <div class="grid gap-1"><Label for="start" class="text-xs">From</Label><Input id="start" v-model="start" type="date" class="h-9" /></div>
                    <div class="grid gap-1"><Label for="end" class="text-xs">To</Label><Input id="end" v-model="end" type="date" class="h-9" /></div>
                    <Button type="submit" size="sm" class="h-9">Apply</Button>
                </form>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card v-for="s in stats" :key="s.key">
                    <CardHeader class="pb-2"><CardDescription>{{ s.label }}</CardDescription></CardHeader>
                    <CardContent><div class="text-2xl font-semibold tabular-nums">{{ s.prefix }}{{ totals[s.key] }}</div></CardContent>
                </Card>
            </div>

            <Alert v-if="usageError" variant="destructive">Could not load Pixelforge usage: {{ usageError }}</Alert>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Per day</CardTitle>
                    <CardDescription>Our provider cost checked against Pixelforge GET /v1/usage. Counts and costs must match exactly.</CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Day</TableHead><TableHead class="text-right">Gens</TableHead><TableHead class="text-right">Credit spend</TableHead>
                                <TableHead class="text-right">Provider cost</TableHead><TableHead class="text-right">Pixelforge</TableHead>
                                <TableHead class="text-right">Profit</TableHead><TableHead>Check</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="d in days" :key="d.date" :class="d.generations === 0 && !d.pixelforge_generations ? 'text-muted-foreground' : ''">
                                <TableCell class="font-mono text-xs">{{ d.date }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ d.generations }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ d.spend }}</TableCell>
                                <TableCell class="text-right font-mono text-xs">{{ d.cost }}</TableCell>
                                <TableCell class="text-right font-mono text-xs">{{ d.pixelforge_cost ?? '–' }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ d.profit }}</TableCell>
                                <TableCell>
                                    <Badge v-if="d.matches === true" class="border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">Match</Badge>
                                    <Badge v-else-if="d.matches === false" variant="destructive">Mismatch</Badge>
                                    <span v-else class="text-muted-foreground">–</span>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle class="text-base">Per day and user</CardTitle></CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Day</TableHead><TableHead>User</TableHead><TableHead class="text-right">Gens</TableHead>
                                <TableHead class="text-right">Credit spend</TableHead><TableHead class="text-right">Provider cost</TableHead><TableHead class="text-right">Profit</TableHead>
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
                                <TableCell :colspan="6" class="py-10 text-center text-muted-foreground">No completed generations in this range.</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
