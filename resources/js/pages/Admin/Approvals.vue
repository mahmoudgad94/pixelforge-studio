<script setup lang="ts">
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Bot, Check, X } from 'lucide-vue-next';
import { computed } from 'vue';

interface Action {
    id: number;
    type: string;
    email: string | null;
    amount: string | null;
    reason: string;
    status: 'pending' | 'approved' | 'rejected' | 'expired';
    requested_by: string | null;
    decided_by: string | null;
    created_at: string;
    expires_at: string;
}

defineProps<{ actions: Action[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Approvals', href: '/admin/approvals' }];
const flash = computed(() => (usePage().props as any).flash?.status as string | undefined);
const decide = (a: Action, verb: 'approve' | 'reject') => router.post(route(`admin.approvals.${verb}`, a.id), {}, { preserveScroll: true });

const statusStyle: Record<Action['status'], string> = {
    pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    approved: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    rejected: 'bg-red-500/15 text-red-700 dark:text-red-300',
    expired: 'bg-secondary text-secondary-foreground',
};
const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Approvals" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Approvals</h1>
                <p class="text-sm text-muted-foreground">
                    Changes requested by AI agents through the admin MCP server. Nothing happens until you approve. Requests expire after 24 hours.
                </p>
            </div>

            <Alert v-if="flash">{{ flash }}</Alert>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"><Bot class="size-4" /> Requests</CardTitle>
                    <CardDescription>Endpoint: POST /mcp/admin with a token from <code>php artisan mcp:token</code></CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead><TableHead>Change</TableHead><TableHead>Reason</TableHead>
                                <TableHead>Requested</TableHead><TableHead>Status</TableHead><TableHead class="text-right">Decision</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="a in actions" :key="a.id">
                                <TableCell class="font-mono text-xs">{{ a.id }}</TableCell>
                                <TableCell>
                                    <div class="font-medium tabular-nums">{{ a.amount }} credits</div>
                                    <div class="text-xs text-muted-foreground">{{ a.email }}</div>
                                </TableCell>
                                <TableCell class="max-w-xs whitespace-normal text-sm">{{ a.reason }}</TableCell>
                                <TableCell class="text-xs text-muted-foreground">{{ a.requested_by }} · {{ when(a.created_at) }}</TableCell>
                                <TableCell>
                                    <Badge class="border-transparent capitalize" :class="statusStyle[a.status]">{{ a.status }}</Badge>
                                    <div v-if="a.decided_by" class="mt-1 text-xs text-muted-foreground">by {{ a.decided_by }}</div>
                                </TableCell>
                                <TableCell class="text-right">
                                    <div v-if="a.status === 'pending'" class="flex justify-end gap-2">
                                        <Button size="sm" variant="outline" @click="decide(a, 'reject')"><X class="size-4" /> Reject</Button>
                                        <Button size="sm" @click="decide(a, 'approve')"><Check class="size-4" /> Approve</Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="actions.length === 0">
                                <TableCell :colspan="6" class="py-10 text-center text-muted-foreground">No requests yet.</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
