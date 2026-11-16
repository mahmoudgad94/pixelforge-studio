<script setup lang="ts">
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import InputError from '@/components/InputError.vue';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/AppLayout.vue';
import { estimate, formatUsd, type PricingTable } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { ImagePlus, LoaderCircle, RectangleHorizontal, RectangleVertical, Sparkles, Square, Upload, Wallet, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    pricing: PricingTable;
    markupPercent: number;
    sizes: string[];
    qualities: string[];
    balance: string;
    balancePicos: string;
    prefill: { prompt: string; size: string; quality: string; reference_images: string[] } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const form = useForm({
    prompt: props.prefill?.prompt ?? '',
    size: props.prefill?.size ?? '1024x1024',
    quality: props.prefill?.quality ?? 'medium',
    reference_images: [...(props.prefill?.reference_images ?? [])] as string[],
});

const sizeMeta: Record<string, { label: string; icon: any }> = {
    '1024x1024': { label: 'Square', icon: Square },
    '1536x1024': { label: 'Landscape', icon: RectangleHorizontal },
    '1024x1536': { label: 'Portrait', icon: RectangleVertical },
};

const refs = computed(() => form.reference_images.filter((u) => u.trim() !== ''));
const est = computed(() => estimate(props.pricing, props.markupPercent, form.prompt, form.size, form.quality, refs.value.length));
const canAfford = computed(() => BigInt(props.balancePicos) >= est.value.max.price);
const refError = computed(() => form.errors.reference_images ?? Object.entries(form.errors).find(([k]) => k.startsWith('reference_images.'))?.[1]);

// Pixelforge test switches. Click to append to the prompt, click again to remove.
const testKeywords = [
    { tag: '[fail]', hint: 'Fails after it was accepted: you are not charged' },
    { tag: '[slow]', hint: 'Takes much longer: leave the page, you get notified' },
    { tag: '[duplicate]', hint: 'Webhooks are sent twice: still charged once' },
    { tag: '[blocked]', hint: 'Rejected by content policy at submit time' },
    { tag: '[overloaded]', hint: 'API returns 503 first: the app retries' },
];
function toggleKeyword(tag: string) {
    form.prompt = form.prompt.includes(tag)
        ? form.prompt.replace(tag, '').replace(/\s{2,}/g, ' ').trim()
        : `${form.prompt.trimEnd()} ${tag}`.trim();
}

// Pixelforge only accepts public URLs, so a file from the device is uploaded to our server first.
const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const uploadError = ref<string | null>(null);
async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    (e.target as HTMLInputElement).value = '';
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        uploadError.value = 'That image is over 5 MB.';
        return;
    }
    uploading.value = true;
    uploadError.value = null;
    try {
        const body = new FormData();
        body.append('image', file);
        const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        const res = await fetch(route('references.store'), {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json?.errors?.image?.[0] ?? json?.message ?? 'Upload failed.');
        form.reference_images.push(json.url);
    } catch (err) {
        uploadError.value = err instanceof Error ? err.message : 'Upload failed.';
    } finally {
        uploading.value = false;
    }
}

function submit() {
    // Ask once, on the click (browsers only allow it from a user gesture), so we can ping when the image is ready.
    if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    form.transform((data) => ({ ...data, reference_images: refs.value })).post(route('generations.store'), { preserveScroll: true });
}

</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6 lg:grid-cols-[1fr_340px]">
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"><Sparkles class="size-5" /> New image</CardTitle>
                    <CardDescription>Describe what you want. You only pay for images that succeed.</CardDescription>
                </CardHeader>
                <form @submit.prevent="submit">
                    <CardContent class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="prompt">Prompt</Label>
                            <Textarea
                                id="prompt"
                                v-model="form.prompt"
                                rows="5"
                                maxlength="4000"
                                required
                                placeholder="A lighthouse on a cliff at sunset, cinematic lighting"
                            />
                            <div class="flex justify-between text-xs text-muted-foreground">
                                <span class="flex flex-wrap items-center gap-1">
                                    Test:
                                    <button
                                        v-for="k in testKeywords"
                                        :key="k.tag"
                                        type="button"
                                        :title="k.hint"
                                        class="rounded border px-1.5 py-0.5 font-mono transition-colors hover:bg-accent hover:text-foreground"
                                        :class="form.prompt.includes(k.tag) ? 'border-primary bg-primary/10 text-foreground' : ''"
                                        @click="toggleKeyword(k.tag)"
                                    >
                                        {{ k.tag }}
                                    </button>
                                </span>
                                <span class="tabular-nums">{{ [...form.prompt].length }} / 4000</span>
                            </div>
                            <InputError :message="form.errors.prompt" />
                        </div>

                        <div class="grid gap-6">
                            <div class="grid gap-2">
                                <Label>Size</Label>
                                <ToggleGroup class="grid w-full grid-cols-3" type="single" :model-value="form.size" @update:model-value="(v) => v && (form.size = v as string)">
                                    <ToggleGroupItem v-for="s in sizes" :key="s" :value="s" :aria-label="s">
                                        <component :is="sizeMeta[s]?.icon" class="size-4" />
                                        {{ sizeMeta[s]?.label ?? s }}
                                    </ToggleGroupItem>
                                </ToggleGroup>
                                <p class="text-xs text-muted-foreground">{{ form.size }} px</p>
                                <InputError :message="form.errors.size" />
                            </div>

                            <div class="grid gap-2">
                                <Label>Quality</Label>
                                <ToggleGroup class="grid w-full grid-cols-3" type="single" :model-value="form.quality" @update:model-value="(v) => v && (form.quality = v as string)">
                                    <ToggleGroupItem v-for="q in qualities" :key="q" :value="q" class="capitalize">{{ q }}</ToggleGroupItem>
                                </ToggleGroup>
                                <InputError :message="form.errors.quality" />
                            </div>
                        </div>

                        <Separator />

                        <div class="grid gap-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <Label>Reference images</Label>
                                    <p class="text-xs text-muted-foreground">Optional, up to 4. Upload from your device or paste a link. Each adds 765 tokens.</p>
                                </div>
                                <div class="flex gap-2">
                                    <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="upload" />
                                    <Button type="button" variant="outline" size="sm" :disabled="form.reference_images.length >= 4 || uploading" @click="fileInput?.click()">
                                        <LoaderCircle v-if="uploading" class="size-4 animate-spin" /><Upload v-else class="size-4" /> Upload
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="form.reference_images.length >= 4"
                                        @click="form.reference_images.push('')"
                                    >
                                        <ImagePlus class="size-4" /> Link
                                    </Button>
                                </div>
                            </div>
                            <p v-if="uploadError" class="text-sm text-destructive">{{ uploadError }}</p>
                            <div v-for="(_, i) in form.reference_images" :key="i" class="flex items-center gap-2">
                                <img
                                    v-if="/^https?:\/\/.+/.test(form.reference_images[i])"
                                    :src="form.reference_images[i]"
                                    alt=""
                                    class="size-9 shrink-0 rounded border object-cover"
                                    loading="lazy"
                                    @error="($event.target as HTMLImageElement).style.visibility = 'hidden'"
                                />
                                <Input v-model="form.reference_images[i]" type="url" placeholder="https://example.com/style.png" />
                                <Button type="button" variant="ghost" size="icon" aria-label="Remove" @click="form.reference_images.splice(i, 1)">
                                    <X class="size-4" />
                                </Button>
                            </div>
                            <InputError :message="refError" />
                        </div>
                    </CardContent>
                    <CardFooter class="flex flex-wrap items-center justify-between gap-3 border-t pt-6">
                        <span class="text-sm text-muted-foreground">
                            About <span class="font-medium text-foreground">{{ formatUsd(est.expected.price) }}</span>
                        </span>
                        <Button type="submit" size="lg" :disabled="form.processing || !form.prompt.trim() || !canAfford">
                            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                            <Sparkles v-else class="size-4" />
                            Generate
                        </Button>
                    </CardFooter>
                </form>
            </Card>

            <div class="space-y-6">
                <Card>
                    <CardHeader class="pb-3">
                        <CardDescription class="flex items-center gap-2"><Wallet class="size-4" /> Balance</CardDescription>
                        <CardTitle class="text-3xl tabular-nums">{{ balance }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BuyCreditsDialog :balance-picos="balancePicos" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-3">
                        <CardDescription>Estimated price</CardDescription>
                        <CardTitle class="text-3xl tabular-nums">{{ formatUsd(est.expected.price) }}</CardTitle>
                        <p class="text-sm text-muted-foreground tabular-nums">
                            {{ formatUsd(est.min.price) }} to {{ formatUsd(est.max.price) }}
                        </p>
                    </CardHeader>
                    <CardContent class="space-y-4 text-sm">
                        <dl class="space-y-1.5 text-muted-foreground">
                            <div class="flex justify-between"><dt>Prompt</dt><dd class="tabular-nums">{{ est.tokens.textMin }} to {{ est.tokens.textMax }} tokens</dd></div>
                            <div class="flex justify-between"><dt>Reference images</dt><dd class="tabular-nums">{{ est.tokens.images }} tokens</dd></div>
                            <div class="flex justify-between"><dt>Image output</dt><dd class="tabular-nums">{{ est.tokens.output }} tokens</dd></div>
                            <div class="flex justify-between"><dt>Reasoning</dt><dd class="tabular-nums">{{ est.tokens.reasoningMin }} to {{ est.tokens.reasoningMax }} tokens</dd></div>
                            <div class="flex justify-between"><dt>Service fee</dt><dd>{{ markupPercent }}%</dd></div>
                        </dl>
                        <Separator />
                        <p class="text-xs leading-relaxed text-muted-foreground">
                            The final price depends on tokens Pixelforge only reports when the image is done. We reserve up to
                            {{ formatUsd(est.max.price) }} while it runs, then charge the exact amount. Failed generations are free.
                        </p>
                        <Alert v-if="!canAfford" variant="destructive">Not enough credits for the worst case. Buy credits to continue.</Alert>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
