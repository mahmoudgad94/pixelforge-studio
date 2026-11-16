<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { estimate, formatUsd, type PricingTable } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    pricing: PricingTable;
    markupPercent: number;
    sizes: string[];
    qualities: string[];
    balance: string;
    balancePicos: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const sizeLabels: Record<string, string> = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };

const refs = computed(() => form.reference_images.filter((u) => u.trim() !== ''));
const est = computed(() => estimate(props.pricing, props.markupPercent, form.prompt, form.size, form.quality, refs.value.length));
const canAfford = computed(() => BigInt(props.balancePicos) >= est.value.max.price);

function addReference() {
    if (form.reference_images.length < 4) form.reference_images.push('');
}

function removeReference(i: number) {
    form.reference_images.splice(i, 1);
}

function submit() {
    form.transform((data) => ({ ...data, reference_images: refs.value })).post(route('generations.store'), {
        preserveScroll: true,
    });
}

function buyCredits() {
    router.post(route('credits.store'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto grid w-full max-w-5xl gap-6 p-4 lg:grid-cols-[1fr_320px]">
            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="prompt">Prompt</Label>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        maxlength="4000"
                        required
                        placeholder="A lighthouse on a cliff at sunset, cinematic lighting"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    />
                    <div class="flex justify-between text-xs text-muted-foreground">
                        <span>Tip: [fail], [slow], [duplicate], [blocked] trigger Pixelforge test behaviour.</span>
                        <span>{{ [...form.prompt].length }} / 4000</span>
                    </div>
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-2">
                    <Label>Size</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="s in sizes"
                            :key="s"
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm"
                            :class="form.size === s ? 'border-primary bg-primary text-primary-foreground' : 'border-input hover:bg-accent'"
                            @click="form.size = s"
                        >
                            {{ sizeLabels[s] ?? s }} <span class="opacity-70">{{ s }}</span>
                        </button>
                    </div>
                    <InputError :message="form.errors.size" />
                </div>

                <div class="grid gap-2">
                    <Label>Quality</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="q in qualities"
                            :key="q"
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm capitalize"
                            :class="form.quality === q ? 'border-primary bg-primary text-primary-foreground' : 'border-input hover:bg-accent'"
                            @click="form.quality = q"
                        >
                            {{ q }}
                        </button>
                    </div>
                    <InputError :message="form.errors.quality" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>Reference images <span class="font-normal text-muted-foreground">(optional, up to 4)</span></Label>
                        <Button type="button" variant="outline" size="sm" :disabled="form.reference_images.length >= 4" @click="addReference">Add URL</Button>
                    </div>
                    <div v-for="(_, i) in form.reference_images" :key="i" class="flex gap-2">
                        <Input v-model="form.reference_images[i]" type="url" placeholder="https://example.com/style.png" />
                        <Button type="button" variant="ghost" size="sm" @click="removeReference(i)">Remove</Button>
                    </div>
                    <InputError :message="form.errors['reference_images'] ?? Object.entries(form.errors).find(([k]) => k.startsWith('reference_images.'))?.[1]" />
                </div>

                <Button type="submit" :disabled="form.processing || !form.prompt.trim() || !canAfford">
                    Generate for about {{ formatUsd(est.expected.price) }}
                </Button>
            </form>

            <aside class="space-y-4">
                <div class="rounded-xl border p-4">
                    <div class="text-sm text-muted-foreground">Balance</div>
                    <div class="text-2xl font-semibold">{{ balance }}</div>
                    <Button class="mt-3 w-full" variant="outline" @click="buyCredits">Buy $10 credits</Button>
                </div>

                <div class="rounded-xl border p-4 text-sm">
                    <div class="text-muted-foreground">Estimated price</div>
                    <div class="text-2xl font-semibold">{{ formatUsd(est.expected.price) }}</div>
                    <div class="mt-1 text-muted-foreground">Between {{ formatUsd(est.min.price) }} and {{ formatUsd(est.max.price) }}</div>
                    <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                        The exact price depends on how many tokens the model uses, which Pixelforge only reports when the image is done.
                        We reserve up to {{ formatUsd(est.max.price) }} while it runs and charge the exact price afterwards. Failed
                        generations are free.
                    </p>
                    <p v-if="!canAfford" class="mt-3 text-xs font-medium text-red-600">Not enough credits for the worst case. Buy credits to continue.</p>
                    <details class="mt-3 text-xs text-muted-foreground">
                        <summary class="cursor-pointer">Token breakdown</summary>
                        <ul class="mt-2 space-y-1">
                            <li>Prompt: ~{{ est.tokens.text }} tokens ({{ est.tokens.textMin }} to {{ est.tokens.textMax }})</li>
                            <li>Reference images: {{ est.tokens.images }} tokens</li>
                            <li>Output: {{ est.tokens.output }} tokens</li>
                            <li>Reasoning: {{ est.tokens.reasoningMin }} to {{ est.tokens.reasoningMax }} tokens</li>
                            <li>Includes {{ markupPercent }}% service fee</li>
                        </ul>
                    </details>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
