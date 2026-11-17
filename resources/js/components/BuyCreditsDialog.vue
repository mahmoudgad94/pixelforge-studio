<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { network } from '@/lib/network';
import { formatUsd } from '@/lib/pricing';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, ShieldCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ balancePicos: string }>();

const PICOS = 1_000_000_000_000n;
const packs = [5, 10, 25];
const open = ref(false);

const form = useForm({ amount: 10, idempotency_key: '' });

// A fresh key every time the dialog opens: one confirmed dialog = one purchase, even on a double click.
watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        form.idempotency_key = crypto.randomUUID();
    }
});

const after = computed(() => BigInt(props.balancePicos) + BigInt(form.amount) * PICOS);

function confirm() {
    form.post(route('credits.store'), {
        preserveScroll: true,
        only: ['balance', 'balancePicos', 'flash'],
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button class="w-full" variant="secondary">Buy credits</Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription>Pick a pack, check the new balance, then confirm. This is a demo checkout, no card is charged.</DialogDescription>
            </DialogHeader>

            <ToggleGroup
                type="single"
                variant="outline"
                class="grid w-full grid-cols-3"
                :model-value="String(form.amount)"
                @update:model-value="(v) => v && (form.amount = Number(v))"
            >
                <ToggleGroupItem v-for="p in packs" :key="p" :value="String(p)" class="h-14 text-base font-semibold tabular-nums"
                    >${{ p }}</ToggleGroupItem
                >
            </ToggleGroup>

            <dl class="grid grid-cols-2 gap-y-1 rounded-lg border bg-muted/40 p-3 text-sm">
                <dt class="text-muted-foreground">Current balance</dt>
                <dd class="text-right tabular-nums">{{ formatUsd(BigInt(balancePicos)) }}</dd>
                <dt class="text-muted-foreground">You add</dt>
                <dd class="text-right tabular-nums">+${{ form.amount }}.00</dd>
                <dt class="font-medium">New balance</dt>
                <dd class="text-right font-semibold tabular-nums">{{ formatUsd(after) }}</dd>
            </dl>

            <p v-if="form.errors.amount || form.errors.idempotency_key" class="text-sm text-destructive">
                {{ form.errors.amount ?? form.errors.idempotency_key }}
            </p>

            <DialogFooter class="gap-2">
                <DialogClose as-child><Button variant="outline" :disabled="form.processing">Cancel</Button></DialogClose>
                <Button :disabled="form.processing || !network.online" @click="confirm">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <ShieldCheck v-else class="size-4" />
                    <template v-if="network.online">Confirm ${{ form.amount }} purchase</template>
                    <template v-else>Offline, try when back online</template>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
