<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{ variant?: 'default' | 'destructive'; class?: HTMLAttributes['class'] }>();
</script>

<template>
    <div
        role="alert"
        :class="
            cn(
                'relative w-full rounded-lg border p-4 text-sm [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg~*]:pl-7',
                variant === 'destructive' ? 'border-destructive/50 text-destructive [&>svg]:text-destructive' : 'bg-background text-foreground',
                props.class,
            )
        "
    >
        <slot />
    </div>
</template>
