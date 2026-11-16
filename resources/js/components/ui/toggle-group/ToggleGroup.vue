<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ToggleGroupRoot, type ToggleGroupRootEmits, type ToggleGroupRootProps, useForwardPropsEmits } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<ToggleGroupRootProps & { class?: HTMLAttributes['class'] }>();
const emits = defineEmits<ToggleGroupRootEmits>();
const delegated = computed(() => {
    const { class: _, ...rest } = props;
    return rest;
});
const forwarded = useForwardPropsEmits(delegated, emits);
</script>

<template>
    <ToggleGroupRoot v-bind="forwarded" :class="cn('inline-flex flex-wrap items-center gap-1 rounded-lg bg-muted p-1', props.class)">
        <slot />
    </ToggleGroupRoot>
</template>
