<script setup lang="ts">
import JsonBlock from '@/components/JsonBlock.vue';
import type { CallDetails } from '@/types';
import { ArrowDownLeft, ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ call: CallDetails; admin?: boolean }>();

const ms = (iso: string | null) => {
    if (!iso) return '–';
    const d = new Date(iso);
    const p = (n: number, w = 2) => String(n).padStart(w, '0');
    return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}.${p(d.getUTCMilliseconds(), 3)} UTC`;
};
const took = computed(() => {
    const { started_at: a, finished_at: b, duration_ms: d } = props.call;
    if (a && b) return `${Math.max(0, new Date(b).getTime() - new Date(a).getTime())} ms`;
    return d !== null ? `${d} ms` : '–';
});
const incoming = computed(() => props.call.channel === 'webhook');
</script>

<template>
    <div class="space-y-3 text-xs" data-testid="call-details">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <ArrowDownLeft v-if="incoming" class="size-3.5 shrink-0 text-violet-600" />
            <ArrowUpRight v-else class="size-3.5 shrink-0 text-blue-600" />
            <span class="font-medium">{{ incoming ? 'Pixelforge sent us' : 'We sent Pixelforge' }}</span>
            <span class="break-all font-mono">{{ call.method ?? '' }} {{ call.url ?? call.action }}</span>
        </div>

        <dl class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-3">
            <div>
                <dt class="text-muted-foreground">Request time</dt>
                <dd class="break-words font-mono">{{ ms(call.started_at) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Finished</dt>
                <dd class="break-words font-mono">{{ ms(call.finished_at) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Duration</dt>
                <dd class="font-mono tabular-nums">{{ took }}</dd>
            </div>
            <template v-if="admin">
                <div>
                    <dt class="text-muted-foreground">Attempt</dt>
                    <dd class="font-mono">{{ call.attempt ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">IP</dt>
                    <dd class="break-all font-mono">{{ call.ip ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Event id</dt>
                    <dd class="break-all font-mono">{{ call.event_id ?? '–' }}</dd>
                </div>
            </template>
        </dl>

        <div v-if="call.error" class="rounded-md border border-destructive/30 bg-destructive/5 p-2" data-testid="call-error">
            <div class="font-medium text-destructive">Error</div>
            <dl class="mt-1 space-y-0.5">
                <div v-if="call.error.code">
                    <dt class="inline text-muted-foreground">Code:</dt>
                    <dd class="inline break-all font-mono">{{ call.error.code }}</dd>
                </div>
                <div v-if="call.error.class">
                    <dt class="inline text-muted-foreground">Class:</dt>
                    <dd class="inline break-all font-mono">{{ call.error.class }}</dd>
                </div>
                <div v-if="call.error.message">
                    <dt class="inline text-muted-foreground">Message:</dt>
                    <dd class="inline break-words">{{ call.error.message }}</dd>
                </div>
            </dl>
        </div>

        <template v-if="call.request">
            <JsonBlock label="Request headers" :data="call.request.headers" />
            <JsonBlock label="Request body" :body="call.request.body" />
        </template>
        <template v-if="call.response">
            <div class="text-xs">
                <span class="text-muted-foreground">{{ incoming ? 'We answered' : 'Response status' }}: </span
                ><span class="font-mono">{{ call.response.status ?? '–' }}</span>
            </div>
            <JsonBlock label="Response headers" :data="call.response.headers" />
            <JsonBlock label="Response body" :body="call.response.body" />
        </template>
    </div>
</template>
