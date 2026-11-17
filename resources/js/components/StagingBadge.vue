<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

const page = usePage<SharedData>();
</script>

<template>
    <Badge v-if="page.props.isStaging" variant="outline" class="shrink-0 border-amber-500/60 px-1.5 py-0 text-[10px] uppercase text-amber-700 dark:text-amber-300">
        Staging
    </Badge>
</template>
