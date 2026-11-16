<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div><label class="text-xs text-muted-foreground">From (UTC)</label><Input v-model="start" type="date" /></div>
                <div><label class="text-xs text-muted-foreground">To (UTC)</label><Input v-model="end" type="date" /></div>
                <Button @click="apply">Apply</Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <div class="rounded-xl border p-4"><div class="text-xs text-muted-foreground">Generations</div><div class="text-xl font-semibold">{{ totals.generations }}</div></div>
                <div class="rounded-xl border p-4"><div class="text-xs text-muted-foreground">Credit spend</div><div class="text-xl font-semibold">{{ totals.spend }}</div></div>
                <div class="rounded-xl border p-4"><div class="text-xs text-muted-foreground">Provider cost</div><div class="text-xl font-semibold">${{ totals.cost }}</div></div>
                <div class="rounded-xl border p-4"><div class="text-xs text-muted-foreground">Profit</div><div class="text-xl font-semibold">{{ totals.profit }}</div></div>
            </div>

            <p v-if="usageError" class="text-sm text-red-600">Could not load Pixelforge usage: {{ usageError }}</p>

            <section>
                <h2 class="mb-2 font-semibold">Per day, checked against Pixelforge GET /v1/usage</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="p-2">Day (UTC, completed)</th><th class="p-2">Gens</th><th class="p-2">Credit spend</th><th class="p-2">Provider cost (ours)</th>
                                <th class="p-2">Pixelforge cost</th><th class="p-2">Profit</th><th class="p-2">Match</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in days" :key="d.date" class="border-t">
                                <td class="p-2 font-mono">{{ d.date }}</td>
                                <td class="p-2">{{ d.generations }}<span v-if="d.pixelforge_generations !== null" class="text-muted-foreground"> / {{ d.pixelforge_generations }}</span></td>
                                <td class="p-2">{{ d.spend }}</td>
                                <td class="p-2 font-mono">{{ d.cost }}</td>
                                <td class="p-2 font-mono">{{ d.pixelforge_cost ?? '–' }}</td>
                                <td class="p-2">{{ d.profit }}</td>
                                <td class="p-2">
                                    <span v-if="d.matches === true" class="text-green-600">✓</span>
                                    <span v-else-if="d.matches === false" class="font-medium text-red-600">mismatch</span>
                                    <span v-else class="text-muted-foreground">–</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="mb-2 font-semibold">Per day and user</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr><th class="p-2">Day</th><th class="p-2">User</th><th class="p-2">Gens</th><th class="p-2">Credit spend</th><th class="p-2">Provider cost</th><th class="p-2">Profit</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in perUser" :key="r.date + r.user" class="border-t">
                                <td class="p-2 font-mono">{{ r.date }}</td><td class="p-2">{{ r.user }}</td><td class="p-2">{{ r.generations }}</td>
                                <td class="p-2">{{ r.spend }}</td><td class="p-2 font-mono">{{ r.cost }}</td><td class="p-2">{{ r.profit }}</td>
                            </tr>
                            <tr v-if="perUser.length === 0"><td colspan="6" class="p-4 text-center text-muted-foreground">No completed generations in this range.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
