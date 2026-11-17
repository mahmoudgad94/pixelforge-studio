<script setup lang="ts">
import { network } from '@/lib/network';
import { router, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, X } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Lives in the app layout, so it keeps watching while the user moves between pages.
 * Polls a tiny JSON endpoint (not a full Inertia reload) only while something is running,
 * then shows a toast and, if allowed, a browser notification when a generation finishes.
 */
interface Update {
    id: number;
    status: string;
    prompt: string;
    price: string | null;
}

const page = usePage<{ inFlight?: number[] }>();
const watching = new Set<number>();
const toast = ref<{ ok: boolean; title: string; body: string } | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let toastTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => page.props.inFlight,
    (ids) => {
        (ids ?? []).forEach((id) => watching.add(id));
        schedule();
    },
    { immediate: true },
);

function schedule(delay = 3000) {
    clearTimeout(timer);
    if (watching.size > 0) timer = setTimeout(poll, delay);
}

function notify(ok: boolean, u: Update) {
    const title = ok ? 'Your image is ready' : 'Generation failed, you were not charged';
    const body = ok ? `${u.prompt} (${u.price})` : u.prompt;
    toast.value = { ok, title, body };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = null), 6000);

    if ('Notification' in window && Notification.permission === 'granted' && document.visibilityState !== 'visible') {
        const n = new Notification(title, { body, icon: '/favicon.svg', tag: `generation-${u.id}` });
        n.onclick = () => {
            window.focus();
            router.visit(route('generations.index'));
        };
    }
}

async function poll() {
    // Offline: nothing to ask. The watcher on network.online polls right away when the connection is back.
    if (!network.online) return;
    if (document.visibilityState === 'hidden' && !('Notification' in window && Notification.permission === 'granted')) {
        // Nobody can see a toast and we may not notify: check again later, cheaply.
        return schedule(10000);
    }
    try {
        const qs = [...watching].map((id) => `ids[]=${id}`).join('&');
        const res = await fetch(`${route('generations.updates')}?${qs}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return schedule(10000);
        const updates: Update[] = await res.json();
        let changed = false;
        for (const u of updates) {
            if (u.status === 'succeeded' || u.status === 'failed') {
                watching.delete(u.id);
                notify(u.status === 'succeeded', u);
                changed = true;
            }
        }
        // The history page shows live cards; refresh just its data when something finished.
        if (changed && route().current('generations.index')) router.reload({ only: ['generations', 'balance'] });
        else if (changed && route().current('generations.create')) router.reload({ only: ['balance', 'balancePicos'] });
    } catch {
        // offline for a moment; try again
    }
    schedule();
}

const onVisible = () => document.visibilityState === 'visible' && schedule(0);
watch(
    () => network.online,
    (online) => online && schedule(0),
);
onMounted(() => document.addEventListener('visibilitychange', onVisible));
onBeforeUnmount(() => {
    clearTimeout(timer);
    clearTimeout(toastTimer);
    document.removeEventListener('visibilitychange', onVisible);
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="translate-y-2 opacity-0"
    >
        <div
            v-if="toast"
            role="status"
            aria-live="polite"
            class="fixed bottom-20 right-4 z-50 flex max-w-sm items-start gap-3 rounded-lg border bg-background px-4 py-3 text-sm shadow-lg"
        >
            <CircleCheck v-if="toast.ok" class="mt-0.5 size-4 shrink-0 text-emerald-600" />
            <CircleAlert v-else class="mt-0.5 size-4 shrink-0 text-destructive" />
            <button type="button" class="text-left" @click="router.visit(route('generations.index'))">
                <div class="font-medium">{{ toast.title }}</div>
                <div class="line-clamp-2 text-muted-foreground">{{ toast.body }}</div>
            </button>
            <button type="button" class="ml-1 text-muted-foreground hover:text-foreground" aria-label="Dismiss" @click="toast = null">
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
