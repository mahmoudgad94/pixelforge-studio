<script setup lang="ts">
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePoll } from '@inertiajs/vue3';
import { Activity, ArrowRight, Bot, Check, ClipboardCheck, Copy, Eye, History, KeyRound, Pencil, ShieldCheck, UserCheck, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Action {
    id: number;
    type: string;
    email: string | null;
    amount: string | null;
    reason: string;
    status: 'pending' | 'approved' | 'rejected' | 'expired';
    client: string | null;
    requested_by: string | null;
    decided_by: string | null;
    decided_at: string | null;
    created_at: string;
    expires_at: string;
}
interface McpCall {
    id: number;
    at: string;
    client: string;
    user: string | null;
    user_email: string | null;
    tool: string | null;
    arguments: string | null;
    outcome: 'ok' | 'error' | 'refused';
    error: string | null;
    duration_ms: number;
}
interface Page<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    pending: Action[];
    history: Page<Action>;
    mcpCalls: Page<McpCall>;
    mcpFilters: { tool: string | null; outcome: string | null; tools: string[] };
    mcp: { url: string; hasToken: boolean; newToken: string | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Approvals', href: '/admin/approvals' }];
const decide = (a: Action, verb: 'approve' | 'reject') => router.post(route(`admin.approvals.${verb}`, a.id), {}, { preserveScroll: true });

// Live: refresh only the three lists every 10 seconds (not the connect instructions), keeping page numbers and filters.
// Inertia pauses the timer while the tab is in the background.
usePoll(10000, { only: ['pending', 'history', 'mcpCalls'], preserveScroll: true, preserveState: true });

// ---- MCP activity filters ----
const toolFilter = ref(props.mcpFilters.tool ?? '');
const outcomeFilter = ref(props.mcpFilters.outcome ?? '');
const outcomes = [
    { value: '', label: 'All outcomes' },
    { value: 'ok', label: 'OK' },
    { value: 'error', label: 'Error' },
    { value: 'refused', label: 'Refused' },
];
function applyFilters() {
    const query = Object.fromEntries(new URLSearchParams(window.location.search));
    delete query.mcp_page; // a new filter starts on page 1
    if (toolFilter.value) query.tool = toolFilter.value;
    else delete query.tool;
    if (outcomeFilter.value) query.outcome = outcomeFilter.value;
    else delete query.outcome;
    router.get(route('admin.approvals.index'), query, { preserveScroll: true, preserveState: true, replace: true, only: ['mcpCalls', 'mcpFilters'] });
}
// Page links keep the other table's page and the filters: only this table's page number changes.
function goToPage(url: string | null, key: 'history_page' | 'mcp_page', only: string[]) {
    if (!url) return;
    const page = new URL(url).searchParams.get(key);
    const query = Object.fromEntries(new URLSearchParams(window.location.search));
    if (page && page !== '1') query[key] = page;
    else delete query[key];
    router.get(route('admin.approvals.index'), query, { preserveScroll: true, preserveState: true, only });
}
const filtered = computed(() => Boolean(props.mcpFilters.tool || props.mcpFilters.outcome));
const clearFilters = () => ((toolFilter.value = ''), (outcomeFilter.value = ''), applyFilters());
// A filter set from a link or the back button should show in the controls.
watch(
    () => [props.mcpFilters.tool, props.mcpFilters.outcome],
    ([tool, outcome]) => ((toolFilter.value = tool ?? ''), (outcomeFilter.value = outcome ?? '')),
);

const outcomeStyle: Record<McpCall['outcome'], string> = {
    ok: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    error: 'bg-red-500/15 text-red-700 dark:text-red-300',
    refused: 'bg-orange-500/15 text-orange-700 dark:text-orange-300',
};
const clock = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    fractionalSecondDigits: 3,
    hour12: false,
});
const clockAt = (iso: string) => clock.format(new Date(iso));
const took = (ms: number) => (ms >= 1000 ? `${(ms / 1000).toFixed(2)} s` : `${ms} ms`);

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
// Replacing or revoking a token cuts off every app that uses it, so both ask first.
const tokenAction = ref<'replace' | 'revoke' | null>(null);
const tokenBusy = ref(false);
const tokenCopy = {
    replace: {
        title: 'Replace the access token?',
        body: 'The current token stops working right away. Any app still using it loses access until you paste in the new one.',
        confirm: 'Replace token',
    },
    revoke: {
        title: 'Revoke the access token?',
        body: 'The token stops working right away and every app using it loses access. You can create a new one later.',
        confirm: 'Revoke token',
    },
};
const sendToken = (action: 'create' | 'replace' | 'revoke') => {
    tokenBusy.value = true;
    const options = { preserveScroll: true, onFinish: () => ((tokenBusy.value = false), (tokenAction.value = null)) };
    if (action === 'revoke') router.delete(route('admin.mcp-token.destroy'), options);
    else router.post(route('admin.mcp-token.store'), {}, options);
};
const createToken = () => (props.mcp.hasToken ? (tokenAction.value = 'replace') : sendToken('create'));
const revokeToken = () => (tokenAction.value = 'revoke');

const tools = [
    { icon: Eye, name: 'get-spend-report', body: 'Spend, provider cost and profit per day and per user, checked against Pixelforge usage.' },
    { icon: Eye, name: 'find-generations', body: 'Recent generations by user and status, with estimated and charged price and errors.' },
    { icon: Eye, name: 'get-user-credits', body: "A user's balance and latest ledger entries." },
    { icon: Eye, name: 'get-action-status', body: 'Whether a request below was approved, rejected or expired.' },
    {
        icon: Pencil,
        name: 'request-credit-adjustment',
        body: 'Asks to add or remove credits. It changes nothing: it creates a request on this page.',
    },
];
const examples = [
    'How much profit did we make yesterday, and does it match Pixelforge?',
    'Which generations failed today, and for which users?',
    'Give reviewer@demo.test $2 back, their image failed twice.',
];
</script>

<template>
    <Head title="Approvals" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <div>
                <h1 class="flex items-center gap-2 text-xl font-semibold tracking-tight">
                    AI approvals
                    <Badge v-if="pending.length" class="border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-300"
                        >{{ pending.length }} waiting</Badge
                    >
                </h1>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    Connect an AI assistant to this app's admin tools. It can read reports and look things up on its own. When it wants to change
                    money, it can only ask: the request waits here, and nothing changes until an admin approves it.
                </p>
            </div>

            <!-- How it works -->
            <div class="grid gap-3 md:grid-cols-4">
                <div
                    v-for="(step, i) in [
                        { icon: Bot, title: 'The AI asks', body: 'For example: give a user $2 back because an image failed.' },
                        { icon: ClipboardCheck, title: 'A request appears here', body: 'With the user, the amount and the AI\'s reason.' },
                        { icon: UserCheck, title: 'An admin decides', body: 'Approve or reject. Requests expire after 24 hours.' },
                        {
                            icon: ShieldCheck,
                            title: 'Only then credits change',
                            body: 'Written to the ledger once. A balance can never go below zero.',
                        },
                    ]"
                    :key="step.title"
                    class="relative rounded-xl border p-4"
                >
                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                        <component :is="step.icon" class="size-4" /> Step {{ i + 1 }}
                    </div>
                    <div class="mt-2 font-medium">{{ step.title }}</div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ step.body }}</p>
                    <ArrowRight v-if="i < 3" class="absolute -right-3 top-1/2 hidden size-4 -translate-y-1/2 text-muted-foreground md:block" />
                </div>
            </div>

            <!-- Waiting for a decision: always on top, highlighted -->
            <Card class="border-amber-500/40 bg-amber-500/5" data-testid="pending-requests">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"><Bot class="size-4" /> Waiting for your decision</CardTitle>
                    <CardDescription>Approving applies the change immediately and records who approved it.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="a in pending"
                        :key="a.id"
                        class="flex flex-col gap-3 rounded-lg border border-amber-500/30 bg-background p-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-mono text-xs text-muted-foreground">#{{ a.id }}</span>
                                <span class="font-medium tabular-nums">{{ a.amount }} credits</span>
                                <span class="break-all text-sm text-muted-foreground">for {{ a.email }}</span>
                            </div>
                            <p class="break-words text-sm">{{ a.reason }}</p>
                            <div class="text-xs text-muted-foreground">
                                Asked by {{ a.client ?? 'an unknown client' }} as {{ a.requested_by }} · {{ when(a.created_at) }} · expires
                                {{ when(a.expires_at) }}
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Button size="sm" variant="outline" @click="decide(a, 'reject')"><X class="size-4" /> Reject</Button>
                            <Button size="sm" @click="decide(a, 'approve')"><Check class="size-4" /> Approve</Button>
                        </div>
                    </div>
                    <p v-if="pending.length === 0" class="py-4 text-center text-sm text-muted-foreground">
                        Nothing is waiting. When an AI asks to change someone's credits, the request shows up here.
                    </p>
                </CardContent>
            </Card>

            <Card data-testid="request-history">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"><History class="size-4" /> Request history</CardTitle>
                    <CardDescription>Every request that was approved, rejected or expired, newest first.</CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead><TableHead>Asked by</TableHead><TableHead>Change</TableHead>
                                <TableHead>Reason from the AI</TableHead><TableHead>Status</TableHead><TableHead>Decision</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="a in history.data" :key="a.id">
                                <TableCell class="font-mono text-xs">{{ a.id }}</TableCell>
                                <TableCell class="whitespace-normal">
                                    <Badge variant="outline" class="font-normal">{{ a.client ?? 'Unknown client' }}</Badge>
                                    <div class="mt-1 text-xs text-muted-foreground">{{ a.requested_by }}</div>
                                    <div class="text-xs text-muted-foreground">{{ when(a.created_at) }}</div>
                                </TableCell>
                                <TableCell>
                                    <div class="font-medium tabular-nums">{{ a.amount }} credits</div>
                                    <div class="text-xs text-muted-foreground">{{ a.email }}</div>
                                </TableCell>
                                <TableCell class="min-w-48 max-w-xs whitespace-normal break-words text-sm">{{ a.reason }}</TableCell>
                                <TableCell>
                                    <Badge class="border-transparent capitalize" :class="statusStyle[a.status]">{{ a.status }}</Badge>
                                </TableCell>
                                <TableCell class="text-xs text-muted-foreground">
                                    <template v-if="a.decided_by && a.decided_at">
                                        <div class="text-foreground">{{ a.decided_by }}</div>
                                        <div>{{ when(a.decided_at) }}</div>
                                    </template>
                                    <template v-else-if="a.status === 'expired'">
                                        <div class="text-foreground">Nobody decided</div>
                                        <div>expired {{ when(a.expires_at) }}</div>
                                    </template>
                                    <template v-else>-</template>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="history.data.length === 0">
                                <TableCell :colspan="6" class="py-10 text-center text-muted-foreground">
                                    No past requests yet. Approved, rejected and expired requests are kept here.
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <nav v-if="history.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t p-3" aria-label="Request history pages">
                        <Button
                            v-for="l in history.links"
                            :key="l.label"
                            :variant="l.active ? 'default' : 'outline'"
                            size="sm"
                            :disabled="!l.url"
                            @click="goToPage(l.url, 'history_page', ['history', 'pending'])"
                        >
                            <span v-html="l.label" />
                        </Button>
                    </nav>
                </CardContent>
            </Card>

            <Card data-testid="mcp-activity">
                <CardHeader>
                    <CardTitle class="flex flex-wrap items-center gap-x-2 gap-y-1 text-base">
                        <Activity class="size-4" /> MCP activity
                        <span class="inline-flex items-center gap-1.5 text-xs font-normal text-muted-foreground">
                            <span class="relative flex size-2"
                                ><span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-60"></span
                                ><span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span></span
                            >Live, updates every 10 seconds
                        </span>
                    </CardTitle>
                    <CardDescription>
                        Every tool call an AI makes, and every connection that was turned away. Arguments are shortened and secrets are removed. Kept
                        for 90 days.
                    </CardDescription>
                    <div class="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center">
                        <label class="sr-only" for="mcp-tool">Filter by tool</label>
                        <select
                            id="mcp-tool"
                            v-model="toolFilter"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring sm:w-56"
                            @change="applyFilters"
                        >
                            <option value="">All tools</option>
                            <option v-for="t in mcpFilters.tools" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <label class="sr-only" for="mcp-outcome">Filter by outcome</label>
                        <select
                            id="mcp-outcome"
                            v-model="outcomeFilter"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring sm:w-44"
                            @change="applyFilters"
                        >
                            <option v-for="o in outcomes" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                        <Button v-if="filtered" size="sm" variant="ghost" @click="clearFilters">Clear filters</Button>
                        <span class="text-xs text-muted-foreground sm:ml-auto"
                            >{{ mcpCalls.total }} {{ mcpCalls.total === 1 ? 'call' : 'calls' }}</span
                        >
                    </div>
                </CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Time</TableHead><TableHead>Client</TableHead><TableHead>User</TableHead><TableHead>Tool</TableHead>
                                <TableHead>Arguments</TableHead><TableHead>Outcome</TableHead><TableHead class="text-right">Took</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="c in mcpCalls.data" :key="c.id">
                                <TableCell class="whitespace-nowrap font-mono text-xs tabular-nums">{{ clockAt(c.at) }}</TableCell>
                                <TableCell class="whitespace-nowrap">{{ c.client }}</TableCell>
                                <TableCell class="whitespace-nowrap" :title="c.user_email ?? ''">{{ c.user ?? '-' }}</TableCell>
                                <TableCell class="whitespace-nowrap font-mono text-xs">{{ c.tool ?? '-' }}</TableCell>
                                <TableCell class="min-w-48 max-w-xs whitespace-normal break-all text-xs text-muted-foreground">{{
                                    c.arguments ?? '-'
                                }}</TableCell>
                                <TableCell class="whitespace-normal">
                                    <Badge class="border-transparent capitalize" :class="outcomeStyle[c.outcome]">{{ c.outcome }}</Badge>
                                    <div v-if="c.error" class="mt-1 max-w-xs break-words text-xs text-muted-foreground">{{ c.error }}</div>
                                </TableCell>
                                <TableCell class="whitespace-nowrap text-right text-xs tabular-nums">{{ took(c.duration_ms) }}</TableCell>
                            </TableRow>
                            <TableRow v-if="mcpCalls.data.length === 0">
                                <TableCell :colspan="7" class="py-10 text-center text-muted-foreground" data-testid="mcp-empty">
                                    <template v-if="filtered"
                                        >No calls match these filters.
                                        <button class="underline" @click="clearFilters">Clear filters</button></template
                                    >
                                    <template v-else
                                        >No activity yet. Connect an AI below and ask it a question: each tool it uses shows up here.</template
                                    >
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <nav v-if="mcpCalls.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t p-3" aria-label="MCP activity pages">
                        <Button
                            v-for="l in mcpCalls.links"
                            :key="l.label"
                            :variant="l.active ? 'default' : 'outline'"
                            size="sm"
                            :disabled="!l.url"
                            @click="goToPage(l.url, 'mcp_page', ['mcpCalls'])"
                        >
                            <span v-html="l.label" />
                        </Button>
                    </nav>
                </CardContent>
            </Card>

            <div class="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base"><KeyRound class="size-4" /> Connect your AI</CardTitle>
                        <CardDescription>
                            Server address: <code class="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{{ mcp.url }}</code
                            >. Only admin accounts can connect.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex flex-wrap gap-1 rounded-md border p-0.5" role="tablist">
                            <Button
                                v-for="c in clients"
                                :key="c.id"
                                size="sm"
                                class="h-8"
                                role="tab"
                                :aria-selected="client === c.id"
                                :variant="client === c.id ? 'secondary' : 'ghost'"
                                @click="client = c.id"
                            >
                                {{ c.label }}
                            </Button>
                        </div>

                        <!-- All three panels share one grid cell, so the box keeps the tallest one's height and nothing jumps when switching. -->
                        <div class="grid grid-cols-[minmax(0,1fr)]" role="tabpanel">
                            <ol
                                class="list-decimal space-y-2 pl-5 text-sm"
                                :class="['[grid-area:1/1]', client === 'claude-ai' ? '' : 'invisible']"
                                :inert="client !== 'claude-ai'"
                                :aria-hidden="client !== 'claude-ai'"
                            >
                                <li>
                                    In Claude, open <span class="font-medium">Settings, Connectors</span>, then
                                    <span class="font-medium">Add custom connector</span>.
                                </li>
                                <li>
                                    Paste the server address
                                    <button
                                        type="button"
                                        class="ml-1 inline-flex items-center gap-1 rounded border px-1.5 py-0.5 font-mono text-xs hover:bg-accent"
                                        @click="copy('url', mcp.url)"
                                    >
                                        <Check v-if="copied === 'url'" class="size-3" /><Copy v-else class="size-3" /> copy
                                    </button>
                                </li>
                                <li>
                                    Claude opens this app: sign in with an admin account and press <span class="font-medium">Authorize</span>. No
                                    token to copy.
                                </li>
                                <li>Ask it something from the examples on the right.</li>
                            </ol>

                            <div
                                class="space-y-3 text-sm"
                                :class="['[grid-area:1/1]', client === 'claude-code' ? '' : 'invisible']"
                                :inert="client !== 'claude-code'"
                                :aria-hidden="client !== 'claude-code'"
                            >
                                <p>Run this, then type <code class="font-mono">/mcp</code> in Claude Code and sign in when the browser opens:</p>
                                <div class="relative">
                                    <pre class="overflow-x-auto rounded-lg bg-muted p-3 pr-12 font-mono text-xs">{{ snippets.claudeCodeOauth }}</pre>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="absolute right-1 top-1 size-8"
                                        aria-label="Copy"
                                        @click="copy('cc', snippets.claudeCodeOauth)"
                                    >
                                        <Check v-if="copied === 'cc'" class="size-4" /><Copy v-else class="size-4" />
                                    </Button>
                                </div>
                                <p class="text-muted-foreground">Or skip sign in and use a token (create one below):</p>
                                <div class="relative">
                                    <pre class="overflow-x-auto rounded-lg bg-muted p-3 pr-12 font-mono text-xs">{{ snippets.claudeCodeToken }}</pre>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="absolute right-1 top-1 size-8"
                                        aria-label="Copy"
                                        @click="copy('cct', snippets.claudeCodeToken)"
                                    >
                                        <Check v-if="copied === 'cct'" class="size-4" /><Copy v-else class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <div
                                class="space-y-3 text-sm"
                                :class="['[grid-area:1/1]', client === 'json' ? '' : 'invisible']"
                                :inert="client !== 'json'"
                                :aria-hidden="client !== 'json'"
                            >
                                <p>
                                    Add this to the app's MCP settings file (for Cursor: <code class="font-mono">~/.cursor/mcp.json</code>). Create a
                                    token below first.
                                </p>
                                <div class="relative">
                                    <pre class="overflow-x-auto rounded-lg bg-muted p-3 pr-12 font-mono text-xs">{{ snippets.json }}</pre>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="absolute right-1 top-1 size-8"
                                        aria-label="Copy"
                                        @click="copy('json', snippets.json)"
                                    >
                                        <Check v-if="copied === 'json'" class="size-4" /><Copy v-else class="size-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm">
                                    <div class="font-medium">Access token</div>
                                    <div class="text-muted-foreground">
                                        {{
                                            mcp.hasToken
                                                ? 'You have a token. Creating a new one replaces it.'
                                                : 'Only needed for apps that cannot sign in.'
                                        }}
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <Button v-if="mcp.hasToken" size="sm" variant="outline" @click="revokeToken">Revoke</Button>
                                    <Button size="sm" @click="createToken">{{ mcp.hasToken ? 'Replace token' : 'Create token' }}</Button>
                                </div>
                            </div>
                            <Dialog :open="tokenAction !== null" @update:open="(open) => !open && !tokenBusy && (tokenAction = null)">
                                <DialogContent v-if="tokenAction" class="sm:max-w-md" data-testid="token-confirm">
                                    <DialogHeader>
                                        <DialogTitle>{{ tokenCopy[tokenAction].title }}</DialogTitle>
                                        <DialogDescription>{{ tokenCopy[tokenAction].body }}</DialogDescription>
                                    </DialogHeader>
                                    <DialogFooter class="gap-2">
                                        <DialogClose as-child><Button variant="outline" :disabled="tokenBusy">Cancel</Button></DialogClose>
                                        <Button variant="destructive" :disabled="tokenBusy" @click="sendToken(tokenAction)">
                                            {{ tokenCopy[tokenAction].confirm }}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
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
                                    <button
                                        type="button"
                                        class="w-full rounded-md border px-3 py-2 text-left text-sm hover:bg-accent"
                                        @click="copy(e, e)"
                                    >
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
