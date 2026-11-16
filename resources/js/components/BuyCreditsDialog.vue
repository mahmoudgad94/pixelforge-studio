<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { router } from '@inertiajs/vue3';
import { LoaderCircle, ShieldCheck } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{ balance: string }>();

const open = ref(false);
const processing = ref(false);

function confirmPurchase() {
    if (processing.value) return;
    processing.value = true;
    router.post(
        route('credits.store'),
        { confirmed: true },
        {
            preserveScroll: true,
            only: ['balance', 'balancePicos', 'flash'],
            onSuccess: () => (open.value = false),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button class="w-full" variant="secondary">Buy $10 credits</Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Buy $10 of credits?</DialogTitle>
                <DialogDescription>Credits are US dollars. This is a demo checkout: no card is charged.</DialogDescription>
            </DialogHeader>

            <dl class="grid grid-cols-2 gap-y-2 rounded-lg border bg-muted/40 p-4 text-sm">
                <dt class="text-muted-foreground">Current balance</dt>
                <dd class="text-right tabular-nums">{{ balance }}</dd>
                <dt class="text-muted-foreground">Adding</dt>
                <dd class="text-right font-medium tabular-nums">+ $10.00</dd>
            </dl>

            <p class="flex items-center gap-2 text-xs text-muted-foreground">
                <ShieldCheck class="size-4 shrink-0" /> Nothing is added until you confirm.
            </p>

            <DialogFooter class="gap-2 sm:gap-0">
                <DialogClose as-child>
                    <Button variant="outline" :disabled="processing">Cancel</Button>
                </DialogClose>
                <Button :disabled="processing" @click="confirmPurchase">
                    <LoaderCircle v-if="processing" class="size-4 animate-spin" />
                    Confirm purchase
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
