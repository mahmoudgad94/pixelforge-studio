<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { CallBody } from '@/types';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    label: string;
    /** A parsed body, or a plain header map. */
    body?: CallBody;
    data?: Record<string, unknown> | null;
}>();

const shown = computed(() => (props.body ? props.body.shown : true));
const value = computed(() => (props.body ? props.body.value : props.data));
const empty = computed(
    () => value.value === null || value.value === undefined || (typeof value.value === 'object' && Object.keys(value.value as object).length === 0),
);
const text = computed(() => (typeof value.value === 'string' ? value.value : JSON.stringify(value.value, null, 2)));

const copied = ref(false);
async function copy() {
    try {
        await navigator.clipboard.writeText(text.value);
    } catch {
        const t = document.createElement('textarea');
        t.value = text.value;
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
    <div class="min-w-0">
        <div class="mb-1 flex items-center justify-between gap-2">
            <div class="text-xs font-medium text-muted-foreground">
                {{ label }}
                <span
                    v-if="body?.truncated"
                    class="ml-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-normal text-amber-700 dark:text-amber-300"
                    >truncated at 64 KB</span
                >
            </div>
            <Button v-if="shown && !empty" type="button" size="sm" variant="ghost" class="h-6 gap-1 px-2 text-xs" @click="copy">
                <Check v-if="copied" class="size-3" /><Copy v-else class="size-3" /> {{ copied ? 'Copied' : 'Copy' }}
            </Button>
        </div>
        <p v-if="!shown" class="rounded-md border border-dashed p-2 text-xs text-muted-foreground">
            Not shown{{ body?.truncated ? ' (too large)' : '' }}.
        </p>
        <p v-else-if="empty" class="rounded-md border border-dashed p-2 text-xs text-muted-foreground">Empty.</p>
        <pre
            v-else
            class="max-h-64 max-w-full overflow-auto whitespace-pre rounded-md border bg-muted/50 p-2 font-mono text-[11px] leading-relaxed"
            tabindex="0"
            >{{ text }}</pre
        >
    </div>
</template>
