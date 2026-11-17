<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleCheck, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

const page = usePage<{ flash: { status?: string | null } }>();
const message = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => page.props.flash?.status,
    (status) => {
        if (!status) return;
        message.value = status;
        clearTimeout(timer);
        timer = setTimeout(() => (message.value = null), 4000);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="translate-y-2 opacity-0"
    >
        <div
            v-if="message"
            role="status"
            aria-live="polite"
            class="fixed bottom-4 right-4 z-50 flex max-w-sm items-center gap-3 rounded-lg border bg-background px-4 py-3 text-sm shadow-lg"
        >
            <CircleCheck class="size-4 shrink-0 text-emerald-600" />
            <span>{{ message }}</span>
            <button type="button" class="ml-1 text-muted-foreground hover:text-foreground" aria-label="Dismiss" @click="message = null">
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
