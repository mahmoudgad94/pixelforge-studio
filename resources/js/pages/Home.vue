<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AuthorBanner from '@/components/AuthorBanner.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, BellRing, Bot, Calculator, CircleDollarSign, Layers, ReceiptText, RotateCcw, ShieldCheck } from 'lucide-vue-next';

defineProps<{
    examples: { quality: string; from: string; to: string }[];
    markupPercent: number;
}>();

const page = usePage<SharedData>();

const steps = [
    { icon: Calculator, title: 'See the price first', body: 'Type a prompt, pick size and quality. The price range updates as you type, from the same rates Pixelforge bills.' },
    { icon: Layers, title: 'Generate, then leave', body: 'The image is made in the background. Close the tab if you like: a browser notification tells you when it is ready.' },
    { icon: ReceiptText, title: 'Pay the real cost', body: 'You are charged the actual token cost plus the platform fee, to the fraction of a cent. Failed images are free.' },
];

const guarantees = [
    { icon: CircleDollarSign, title: 'Never charged twice', body: 'Duplicate or out of order webhooks are ignored. The database itself refuses a second charge for the same image.' },
    { icon: ShieldCheck, title: 'Never charged for nothing', body: 'The worst case is held while the image runs, then released. Failures, policy blocks and timeouts cost $0.' },
    { icon: RotateCcw, title: 'Survives a busy API', body: 'Rate limits, the 3 at a time limit and overloads are retried with backoff. A reconciler catches anything a webhook missed.' },
    { icon: BellRing, title: 'Live status', body: 'Sending, queued, processing, done: every card updates on its own, with the estimate next to the final price.' },
    { icon: Bot, title: 'Admin tools and MCP', body: 'Daily spend report checked against Pixelforge usage. Admins can ask an AI assistant to adjust credits, and a human approves it.' },
];
</script>

<template>
    <Head title="See the price before you generate">
        <meta name="description" content="Generate images with Pixelforge and pay only for what each image really costs. Price shown before you start." />
    </Head>

    <div class="min-h-svh bg-background text-foreground">
        <AuthorBanner />

        <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 md:px-6">
            <Link :href="route('home')" class="flex items-center gap-2 font-semibold">
                <span class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"><AppLogoIcon class="size-5" /></span>
                Pixelforge Studio
            </Link>
            <Button v-if="page.props.auth.user" as-child size="sm"><Link :href="route('generations.create')">Open studio <ArrowRight class="size-4" /></Link></Button>
            <Button v-else as-child size="sm" variant="outline"><Link :href="route('login')">Sign in</Link></Button>
        </header>

        <main>
            <section class="mx-auto grid max-w-6xl items-center gap-10 px-4 pb-16 pt-8 md:px-6 md:pt-14 lg:grid-cols-[1.1fr_1fr]">
                <div class="space-y-6">
                    <Badge variant="outline" class="gap-1.5 font-normal"><span class="size-1.5 rounded-full bg-emerald-500" /> Pay per image, no subscription</Badge>
                    <h1 class="text-4xl font-semibold tracking-tight md:text-5xl">See the price<br />before you generate.</h1>
                    <p class="max-w-xl text-lg text-muted-foreground">
                        Make images with Pixelforge and pay exactly what each one costs, plus a {{ markupPercent }}% fee. No credits lost to failures, no surprise charges.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button as-child size="lg">
                            <Link :href="page.props.auth.user ? route('generations.create') : route('login')">
                                {{ page.props.auth.user ? 'Open studio' : 'Try the demo' }} <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button as-child size="lg" variant="outline"><a href="#pricing">See prices</a></Button>
                    </div>
                    <p v-if="!page.props.auth.user" class="text-sm text-muted-foreground">The demo account is already filled in on the sign in page.</p>
                </div>

                <!-- A static picture of the estimate panel, so the idea is clear at a glance. -->
                <div class="relative" aria-hidden="true">
                    <div class="absolute -inset-6 -z-10 rounded-3xl bg-gradient-to-br from-violet-500/20 via-fuchsia-500/10 to-transparent blur-2xl" />
                    <div class="rounded-2xl border bg-card p-5 shadow-xl">
                        <div class="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">A lighthouse on a cliff at sunset, cinematic lighting</div>
                        <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                            <div class="rounded-md border px-2 py-2">Low</div>
                            <div class="rounded-md border border-primary bg-primary/5 px-2 py-2 font-medium">Medium</div>
                            <div class="rounded-md border px-2 py-2">High</div>
                        </div>
                        <div class="mt-5 flex items-end justify-between border-t pt-4">
                            <div>
                                <div class="text-xs text-muted-foreground">Estimated price</div>
                                <div class="text-3xl font-semibold tabular-nums">{{ examples[1]?.from }}</div>
                                <div class="text-xs text-muted-foreground tabular-nums">up to {{ examples[1]?.to }}</div>
                            </div>
                            <div class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Generate</div>
                        </div>
                        <div class="mt-4 flex items-center gap-2 rounded-md bg-emerald-500/10 px-3 py-2 text-xs text-emerald-700 dark:text-emerald-300">
                            <ShieldCheck class="size-4" /> If it fails, you pay $0.00
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-y bg-muted/30">
                <div class="mx-auto grid max-w-6xl gap-6 px-4 py-14 md:grid-cols-3 md:px-6">
                    <div v-for="(s, i) in steps" :key="s.title" class="space-y-2">
                        <div class="flex items-center gap-3">
                            <span class="flex size-9 items-center justify-center rounded-lg border bg-background"><component :is="s.icon" class="size-4" /></span>
                            <span class="text-xs font-medium text-muted-foreground">Step {{ i + 1 }}</span>
                        </div>
                        <h3 class="font-semibold">{{ s.title }}</h3>
                        <p class="text-sm text-muted-foreground">{{ s.body }}</p>
                    </div>
                </div>
            </section>

            <section id="pricing" class="mx-auto max-w-6xl scroll-mt-8 px-4 py-16 md:px-6">
                <h2 class="text-2xl font-semibold tracking-tight">What one square image costs</h2>
                <p class="mt-2 text-muted-foreground">For a typical one line prompt. Bigger sizes, longer prompts and reference images change the price, and you always see it before you start.</p>
                <div class="mt-8 grid gap-4 sm:grid-cols-3">
                    <div v-for="e in examples" :key="e.quality" class="rounded-xl border bg-card p-5">
                        <div class="text-sm capitalize text-muted-foreground">{{ e.quality }} quality</div>
                        <div class="mt-2 text-2xl font-semibold tabular-nums">{{ e.from }}</div>
                        <div class="text-sm text-muted-foreground tabular-nums">to {{ e.to }}</div>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-4 pb-20 md:px-6">
                <h2 class="text-2xl font-semibold tracking-tight">Built so the money is always right</h2>
                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="g in guarantees" :key="g.title" class="rounded-xl border p-5">
                        <component :is="g.icon" class="size-5 text-muted-foreground" />
                        <h3 class="mt-3 font-semibold">{{ g.title }}</h3>
                        <p class="mt-1 text-sm text-muted-foreground">{{ g.body }}</p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm text-muted-foreground md:px-6">
                <span>Pixelforge Studio. Take-home project, Pixelforge is a test API.</span>
                <a href="https://mahmoudebaa.com" target="_blank" rel="noopener" class="hover:text-foreground">Made by Mahmoud Ebaa</a>
            </div>
        </footer>
    </div>
</template>
