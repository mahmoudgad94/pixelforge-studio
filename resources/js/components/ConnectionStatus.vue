<script setup lang="ts">
import { network, report } from '@/lib/network';
import { CircleAlert, Wifi, WifiOff, X } from 'lucide-vue-next';
</script>

<template>
    <!-- Offline / back online: a slim bar at the top of every page. -->
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="-translate-y-full opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="-translate-y-full opacity-0"
    >
        <div
            v-if="!network.online || network.restored"
            role="status"
            aria-live="polite"
            data-testid="connection-status"
            class="fixed inset-x-0 top-0 z-[60] flex items-center justify-center gap-2 px-4 py-2 text-center text-sm font-medium"
            :class="network.online ? 'bg-emerald-600 text-white' : 'bg-amber-500 text-amber-950'"
        >
            <template v-if="!network.online">
                <WifiOff class="size-4 shrink-0" />
                You are offline. What you typed stays here; sending is paused until you are back.
            </template>
            <template v-else> <Wifi class="size-4 shrink-0" /> Back online. The page was refreshed. </template>
        </div>
    </Transition>

    <!-- A request that failed: what happened and what to do. -->
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="translate-y-2 opacity-0"
    >
        <div
            v-if="network.problem"
            role="alert"
            data-testid="request-problem"
            class="fixed bottom-4 left-1/2 z-[60] flex w-[calc(100%-2rem)] max-w-md -translate-x-1/2 items-start gap-3 rounded-lg border border-destructive/30 bg-background px-4 py-3 text-sm shadow-lg"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0 text-destructive" />
            <span class="flex-1">{{ network.problem }}</span>
            <button type="button" class="text-muted-foreground hover:text-foreground" aria-label="Dismiss" @click="report(null)">
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
