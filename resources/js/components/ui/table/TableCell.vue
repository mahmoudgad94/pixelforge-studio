<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{ class?: HTMLAttributes['class']; colspan?: number }>();
</script>

<template>
    <td :colspan="colspan" :class="cn('whitespace-nowrap p-3 align-middle', props.class)"><slot /></td>
</template>
