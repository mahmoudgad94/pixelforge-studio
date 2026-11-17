import * as Sentry from '@sentry/vue';
import type { App } from 'vue';

/**
 * Browser error reporting. The DSN and environment come from <meta> tags the server renders from
 * SENTRY_LARAVEL_DSN / APP_ENV, so one build works everywhere and no DSN means Sentry stays off.
 */
export function initSentry(app: App) {
    const meta = (name: string) => document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)?.content || undefined;
    const dsn = meta('sentry-dsn');
    if (!dsn) return;

    Sentry.init({
        app,
        dsn,
        environment: meta('sentry-environment'),
        release: meta('sentry-release'),
        sendDefaultPii: false,
        integrations: [Sentry.browserTracingIntegration()],
        tracesSampleRate: 0.1,
        // Being offline or a dropped connection is handled in the UI (lib/network.ts); it is not a bug.
        ignoreErrors: ['Network Error', 'Failed to fetch', 'Load failed', 'NetworkError when attempting to fetch resource.'],
    });

    const userId = meta('sentry-user');
    if (userId) Sentry.setUser({ id: userId });
}
