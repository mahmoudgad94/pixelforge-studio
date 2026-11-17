<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookOpen, ImagePlus } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Shown for 403, 404, 419, 500 and 503 instead of the framework's plain error pages.
 * It may render outside the web middleware (an unknown URL), so it reads nothing from shared props.
 */
const props = defineProps<{ status: number }>();

const copy: Record<number, { title: string; body: string }> = {
    403: { title: 'This page is not for your account', body: 'It needs an admin account. Your images and credit are untouched.' },
    404: {
        title: 'This page does not exist',
        body: 'The link may be old or mistyped. Nothing was charged, and your images are where you left them.',
    },
    419: {
        title: 'This page expired',
        body: 'You were away for a while, so the form timed out. Go back, reload, and try again. Nothing was charged.',
    },
    500: {
        title: 'Something broke on our side',
        body: 'The error is logged. If you were generating, no charge goes through for an image that did not finish.',
    },
    503: { title: 'Back in a moment', body: 'Pixelforge Studio is restarting or being updated. Try again in a minute.' },
};

const content = computed(() => copy[props.status] ?? copy[500]);
const goBack = () => (window.history.length > 1 ? window.history.back() : window.location.assign('/'));
</script>

<template>
    <Head :title="content.title" />

    <div class="flex min-h-svh flex-col overflow-x-hidden bg-background text-foreground">
        <header class="mx-auto flex w-full max-w-6xl items-center px-4 py-5 md:px-6">
            <Link href="/" class="flex items-center gap-2 font-semibold">
                <span class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                    ><AppLogoIcon class="size-5"
                /></span>
                Pixelforge Studio
            </Link>
        </header>

        <main class="mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center px-4 pb-24 text-center" data-testid="error-page">
            <!-- An empty canvas: the "image" that never got generated. -->
            <div class="relative mb-8" aria-hidden="true">
                <div class="absolute -inset-6 -z-10 rounded-3xl bg-gradient-to-br from-violet-500/20 via-fuchsia-500/10 to-transparent blur-2xl" />
                <div class="flex size-40 flex-col items-center justify-center gap-1 rounded-2xl border-2 border-dashed bg-card shadow-xl">
                    <span class="text-5xl font-semibold tabular-nums tracking-tight">{{ status }}</span>
                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-300">$0.00 charged</span>
                </div>
            </div>

            <h1 class="text-2xl font-semibold tracking-tight md:text-3xl">{{ content.title }}</h1>
            <p class="mt-3 text-muted-foreground">{{ content.body }}</p>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <Button as-child
                    ><Link href="/generate"><ImagePlus class="size-4" /> Generate an image</Link></Button
                >
                <Button variant="outline" @click="goBack"><ArrowLeft class="size-4" /> Go back</Button>
                <Button as-child variant="ghost"
                    ><Link href="/guide"><BookOpen class="size-4" /> Product tour</Link></Button
                >
            </div>
        </main>
    </div>
</template>
