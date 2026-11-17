<script setup lang="ts">
import { Button, buttonVariants } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

// Plain HTML forms on purpose: approving redirects to the client (e.g. claude.ai), which an XHR can't follow.
defineProps<{
    appName: string;
    clientName: string;
    forbidden: boolean;
    scopes?: string[];
    state?: string | null;
    clientId?: string;
    redirectHost?: string | null;
    authToken?: string;
    csrfToken?: string;
}>();
</script>

<template>
    <AuthLayout
        v-if="forbidden"
        title="Admins only"
        :description="`Only ${appName} admins can connect ${clientName}. Sign out, then connect again with an admin account.`"
    >
        <Head title="Not allowed" />
        <Link :href="route('logout')" method="post" as="button" :class="[buttonVariants({ variant: 'outline' }), 'w-full']"> Sign out </Link>
    </AuthLayout>

    <AuthLayout v-else :title="`Connect ${clientName}`" :description="`${clientName} wants to access your ${appName} admin account.`">
        <Head title="Authorize" />

        <div class="space-y-6">
            <div class="rounded-md border p-4 text-sm">
                <p class="mb-2 font-medium">It will be able to:</p>
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li v-for="scope in scopes" :key="scope">{{ scope }}</li>
                </ul>
                <p v-if="redirectHost" class="mt-3 text-muted-foreground">
                    You will be sent back to <span class="font-medium text-foreground">{{ redirectHost }}</span
                    >.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <form method="post" action="/oauth/authorize">
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <input type="hidden" name="_method" value="DELETE" />
                    <input type="hidden" name="state" :value="state ?? ''" />
                    <input type="hidden" name="client_id" :value="clientId" />
                    <input type="hidden" name="auth_token" :value="authToken" />
                    <Button type="submit" variant="outline" class="w-full">Cancel</Button>
                </form>

                <form method="post" action="/oauth/authorize">
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <input type="hidden" name="state" :value="state ?? ''" />
                    <input type="hidden" name="client_id" :value="clientId" />
                    <input type="hidden" name="auth_token" :value="authToken" />
                    <Button type="submit" class="w-full">Authorize</Button>
                </form>
            </div>
        </div>
    </AuthLayout>
</template>
