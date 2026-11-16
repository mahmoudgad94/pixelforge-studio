<script setup lang="ts">
import AuthorBanner from '@/components/AuthorBanner.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col">
        <AuthorBanner />
        <AuthLayout :title="title" :description="description" class="flex-1">
            <slot />
        </AuthLayout>
    </div>
</template>
