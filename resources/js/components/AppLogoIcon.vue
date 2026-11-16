<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- Pixelforge Studio mark: a few pixels and a spark. Same shape as public/favicon.svg. -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="8 8 50 48" :class="className" v-bind="$attrs">
        <g fill="currentColor">
            <rect x="12" y="12" width="12" height="12" rx="2.5" />
            <rect x="26" y="12" width="12" height="12" rx="2.5" />
            <rect x="12" y="26" width="12" height="12" rx="2.5" />
            <rect x="12" y="40" width="12" height="12" rx="2.5" opacity=".55" />
            <rect x="26" y="26" width="12" height="12" rx="2.5" opacity=".55" />
            <path d="M46 34l2.6 6.4L55 43l-6.4 2.6L46 52l-2.6-6.4L37 43l6.4-2.6z" />
        </g>
    </svg>
</template>
