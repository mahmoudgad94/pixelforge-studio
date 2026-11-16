<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ToggleGroupItem, type ToggleGroupItemProps, useForwardProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<ToggleGroupItemProps & { class?: HTMLAttributes['class'] }>();
const delegated = computed(() => {
    const { class: _, ...rest } = props;
    return rest;
});
const forwarded = useForwardProps(delegated);
</script>

<template>
    <ToggleGroupItem
        v-bind="forwarded"
        :class="
            cn(
                'inline-flex h-9 items-center justify-center gap-1.5 rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 data-[state=on]:bg-background data-[state=on]:text-foreground data-[state=on]:shadow-sm',
                props.class,
            )
        "
    >
        <slot />
    </ToggleGroupItem>
</template>
