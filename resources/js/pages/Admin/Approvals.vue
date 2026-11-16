<script setup lang="ts">
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ArrowRight, Bot, Check, ClipboardCheck, Copy, Eye, KeyRound, Pencil, ShieldCheck, UserCheck, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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

const props = defineProps<{
    actions: Action[];
    mcp: { url: string; hasToken: boolean; newToken: string | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Approvals', href: '/admin/approvals' }];
const decide = (a: Action, verb: 'approve' | 'reject') => router.post(route(`admin.approvals.${verb}`, a.id), {}, { preserveScroll: true });
const pending = computed(() => props.actions.filter((a) => a.status === 'pending').length);

const statusStyle: Record<Action['status'], string> = {
    pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    approved: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    rejected: 'bg-red-500/15 text-red-700 dark:text-red-300',
    expired: 'bg-secondary text-secondary-foreground',
};
const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });

// ---- Connect an AI -------------------------------------------------------
type Client = 'claude-ai' | 'claude-code' | 'json';
const client = ref<Client>('claude-ai');
const token = computed(() => props.mcp.newToken ?? 'YOUR_TOKEN');
const clients: { id: Client; label: string }[] = [
    { id: 'claude-ai', label: 'Claude (web and desktop)' },
    { id: 'claude-code', label: 'Claude Code' },
    { id: 'json', label: 'Cursor and other MCP apps' },
];
const snippets = computed(() => ({
    claudeCodeOauth: `claude mcp add --transport http pixelforge ${props.mcp.url}`,
    claudeCodeToken: `claude mcp add --transport http pixelforge ${props.mcp.url} \\\n  --header "Authorization: Bearer ${token.value}"`,
    json: JSON.stringify({ mcpServers: { pixelforge: { url: props.mcp.url, headers: { Authorization: `Bearer ${token.value}` } } } }, null, 2),
}));

const copied = ref<string | null>(null);
async function copy(key: string, text: string) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        /* clipboard blocked: the text is still selectable */
    }
}
const createToken = () => router.post(route('admin.mcp-token.store'), {}, { preserveScroll: true });
const revokeToken = () => router.delete(route('admin.mcp-token.destroy'), { preserveScroll: true });

const tools = [
    { icon: Eye, name: 'get-spend-report', body: 'Spend, provider cost and profit per day and per user, checked against Pixelforge usage.' },
    { icon: Eye, name: 'find-generations', body: 'Recent generations by user and status, with estimated and charged price and errors.' },
    { icon: Eye, name: 'get-user-credits', body: "A user's balance and latest ledger entries." },
    { icon: Eye, name: 'get-action-status', body: 'Whether a request below was approved, rejected or expired.' },
    { icon: Pencil, name: 'request-credit-adjustment', body: 'Asks to add or remove credits. It changes nothing: it creates a request on this page.' },
];
const examples = ['How much profit did we make yesterday, and does it match Pixelforge?', 'Which generations failed today, and for which users?', 'Give reviewer@demo.test $2 back, their image failed twice.'];
</script>

<template>
    <Head title="Approvals" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <div>
                <h1 class="flex items-center gap-2 text-xl font-semibold tracking-tight">
                    AI approvals
                    <Badge v-if="pending" class="border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-300">{{ pending }} waiting</Badge>
                </h1>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    Connect an AI assistant to this app's admin tools. It can read reports and look things up on its own. When it wants to change money, it can only
                    ask: the request waits here, and nothing changes until an admin approves it.
                </p>
            </div>

            <!-- How it works -->
            <div class="grid gap-3 md:grid-cols-4">
                <div v-for="(step, i) in [
                    { icon: Bot, title: 'The AI asks', body: 'For example: give a user $2 back because an image failed.' },
                    { icon: ClipboardCheck, title: 'A request appears here', body: 'With the user, the amount and the AI\'s reason.' },
                    { icon: UserCheck, title: 'An admin decides', body: 'Approve or reject. Requests expire after 24 hours.' },
                    { icon: ShieldCheck, title: 'Only then credits change', body: 'Written to the ledger once. A balance can never go below zero.' },
                ]" :key="step.title" class="relative rounded-xl border p-4">
                    <div class="flex items-center gap-2 text-xs text-muted-foreground"><component :is="step.icon" class="size-4" /> Step {{ i + 1 }}</div>
                    <div class="mt-2 font-medium">{{ step.title }}</div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ step.body }}</p>
                    <ArrowRight v-if="i < 3" class="absolute -right-3 top-1/2 hidden size-4 -translate-y-1/2 text-muted-foreground md:block" />
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"><Bot class="size-4" /> Requests</CardTitle>
                    <CardDescription>Newest first. Approving applies the change immediately and records who approved it.</CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead><TableHead>Change</TableHead><TableHead>Reason from the AI</TableHead>
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
                                    <div v-else-if="a.status === 'pending'" class="mt-1 text-xs text-muted-foreground">expires {{ when(a.expires_at) }}</div>
                                </TableCell>
                                <TableCell class="text-right">
                                    <div v-if="a.status === 'pending'" class="flex justify-end gap-2">
                                        <Button size="sm" variant="outline" @click="decide(a, 'reject')"><X class="size-4" /> Reject</Button>
                                        <Button size="sm" @click="decide(a, 'approve')"><Check class="size-4" /> Approve</Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="actions.length === 0">
                                <TableCell :colspan="6" class="py-10 text-center text-muted-foreground">
                                    No requests yet. Connect an AI below and ask it to adjust someone's credits.
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base"><KeyRound class="size-4" /> Connect your AI</CardTitle>
                        <CardDescription>
                            Server address: <code class="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{{ mcp.url }}</code>. Only admin accounts can connect.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex flex-wrap gap-1 rounded-md border p-0.5">
                            <Button v-for="c in clients" :key="c.id" size="sm" class="h-8" :variant="client === c.id ? 'secondary' : 'ghost'" @click="client = c.id">
                                {{ c.label }}
                            </Button>
                        </div>

                        <ol v-if="client === 'claude-ai'" class="list-decimal space-y-2 pl-5 text-sm">
                            <li>In Claude, open <span class="font-medium">Settings, Connectors</span>, then <span class="font-medium">Add custom connector</span>.</li>
                            <li>
                                Paste the server address
                                <button type="button" class="ml-1 inline-flex items-center gap-1 rounded border px-1.5 py-0.5 font-mono text-xs hover:bg-accent" @click="copy('url', mcp.url)">
                                    <Check v-if="copied === 'url'" class="size-3" /><Copy v-else class="size-3" /> copy
                                </button>
                            </li>
                            <li>Claude opens this app: sign in with an admin account and press <span class="font-medium">Authorize</span>. No token to copy.</li>
                            <li>Ask it something from the examples on the right.</li>
                        </ol>

                        <div v-else-if="client === 'claude-code'" class="space-y-3 text-sm">
                            <p>Run this, then type <code class="font-mono">/mcp</code> in Claude Code and sign in when the browser opens:</p>
                            <div class="relative">
                                <pre class="overflow-x-auto rounded-lg bg-muted p-3 pr-12 font-mono text-xs">{{ snippets.claudeCodeOauth }}</pre>
                                <Button size="icon" variant="ghost" class="absolute right-1 top-1 size-8" aria-label="Copy" @click="copy('cc', snippets.claudeCodeOauth)">
                                    <Check v-if="copied === 'cc'" class="size-4" /><Copy v-else class="size-4" />
                                </Button>
                            </div>
                            <p class="text-muted-foreground">Or skip sign in and use a token (create one below):</p>
                            <div class="relative">
                                <pre class="overflow-x-auto rounded-lg bg-muted p-3 pr-12 font-mono text-xs">{{ snippets.claudeCodeToken }}</pre>
                                <Button size="icon" variant="ghost" class="absolute right-1 top-1 size-8" aria-label="Copy" @click="copy('cct', snippets.claudeCodeToken)">
                                    <Check v-if="copied === 'cct'" class="size-4" /><Copy v-else class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div v-else class="space-y-3 text-sm">
                            <p>Add this to the app's MCP settings file (for Cursor: <code class="font-mono">~/.cursor/mcp.json</code>). Create a token below first.</p>
                            <div class="relative">
                                <pre class="overflow-x-auto rounded-lg bg-muted p-3 pr-12 font-mono text-xs">{{ snippets.json }}</pre>
                                <Button size="icon" variant="ghost" class="absolute right-1 top-1 size-8" aria-label="Copy" @click="copy('json', snippets.json)">
                                    <Check v-if="copied === 'json'" class="size-4" /><Copy v-else class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="rounded-lg border p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm">
                                    <div class="font-medium">Access token</div>
                                    <div class="text-muted-foreground">{{ mcp.hasToken ? 'You have a token. Creating a new one replaces it.' : 'Only needed for apps that cannot sign in.' }}</div>
                                </div>
                                <div class="flex gap-2">
                                    <Button v-if="mcp.hasToken" size="sm" variant="outline" @click="revokeToken">Revoke</Button>
                                    <Button size="sm" @click="createToken">{{ mcp.hasToken ? 'Replace token' : 'Create token' }}</Button>
                                </div>
                            </div>
                            <Alert v-if="mcp.newToken" class="mt-3">
                                <div class="text-sm font-medium">Copy it now, it is shown only once.</div>
                                <div class="mt-2 flex items-center gap-2">
                                    <code class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 font-mono text-xs">{{ mcp.newToken }}</code>
                                    <Button size="sm" variant="outline" @click="copy('token', mcp.newToken!)">
                                        <Check v-if="copied === 'token'" class="size-4" /><Copy v-else class="size-4" /> Copy
                                    </Button>
                                </div>
                            </Alert>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">What the AI can do</CardTitle>
                        <CardDescription>Reading is instant. Changing money always comes back here for approval.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <ul class="space-y-3">
                            <li v-for="t in tools" :key="t.name" class="flex gap-3 text-sm">
                                <component :is="t.icon" class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <div>
                                    <div class="font-mono text-xs">{{ t.name }}</div>
                                    <div class="text-muted-foreground">{{ t.body }}</div>
                                </div>
                            </li>
                        </ul>
                        <div class="border-t pt-4">
                            <div class="mb-2 text-xs font-medium text-muted-foreground">Try asking</div>
                            <ul class="space-y-2">
                                <li v-for="e in examples" :key="e">
                                    <button type="button" class="w-full rounded-md border px-3 py-2 text-left text-sm hover:bg-accent" @click="copy(e, e)">
                                        <span class="mr-1">{{ e }}</span>
                                        <span class="text-xs text-muted-foreground">{{ copied === e ? 'copied' : '' }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
