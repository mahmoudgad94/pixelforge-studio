import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * One app-wide view of the connection and of page visits:
 * - online: follows the browser's online/offline events.
 * - navigatingTo: the path of a full page visit in progress (drives the skeleton screens).
 * - problem: the last request that failed, as a message the user can act on.
 * Registered once in app.ts, so it works on every page and layout.
 */
export const network = reactive({
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    /** True for a few seconds after the connection comes back, to say so. */
    restored: false,
    navigatingTo: null as string | null,
    problem: null as string | null,
});

let skeletonTimer: ReturnType<typeof setTimeout> | undefined;
let restoredTimer: ReturnType<typeof setTimeout> | undefined;
let problemTimer: ReturnType<typeof setTimeout> | undefined;

/** Below this a visit is fast enough that a skeleton would only flash. */
const SKELETON_DELAY_MS = 120;

export function report(message: string | null) {
    network.problem = message;
    clearTimeout(problemTimer);
    if (message) problemTimer = setTimeout(() => (network.problem = null), 8000);
}

function messageFor(status: number): string {
    if (status === 419) return 'Your session expired. Reloading the page so you can continue.';
    if (status === 429) return 'Too many requests in a short time. Wait a few seconds and try again.';
    if (status === 503) return 'The app is updating or busy. Try again in a moment.';
    if (status >= 500) return 'Something went wrong on our side. Nothing was charged. Try again.';
    if (status === 403) return 'You do not have access to that page.';
    if (status === 404) return 'That page no longer exists.';
    return `The request failed (${status}). Try again.`;
}

export function installNetworkHandling() {
    window.addEventListener('online', () => {
        network.online = true;
        network.restored = true;
        report(null);
        clearTimeout(restoredTimer);
        restoredTimer = setTimeout(() => (network.restored = false), 4000);
        // Whatever is on screen may be stale: refresh the current page's data in place.
        router.reload();
    });
    window.addEventListener('offline', () => {
        network.online = false;
        network.restored = false;
    });

    router.on('start', (event) => {
        const visit = event.detail.visit;
        // Only full GET visits to another page get a skeleton; partial reloads, prefetches and form posts keep the page.
        if (visit.method !== 'get' || visit.only.length > 0 || visit.prefetch || visit.preserveState === true) return;
        const target = visit.url.pathname;
        if (target === window.location.pathname) return;
        clearTimeout(skeletonTimer);
        skeletonTimer = setTimeout(() => (network.navigatingTo = target), SKELETON_DELAY_MS);
    });
    router.on('finish', () => {
        clearTimeout(skeletonTimer);
        network.navigatingTo = null;
    });

    // No response at all: offline, DNS, tunnel down. Keep the page and the form as they are.
    router.on('exception', (event) => {
        report(
            network.online
                ? 'Could not reach the server. Your input is kept; try again in a moment.'
                : 'You are offline. Your input is kept; send it again when you are back online.',
        );
        event.preventDefault();
    });

    // A response that is not an Inertia page (an error page, a proxy error): explain it instead of a raw modal.
    router.on('invalid', (event) => {
        const status = event.detail.response.status;
        report(messageFor(status));
        if (status === 419) setTimeout(() => window.location.reload(), 1500);
        event.preventDefault();
    });
}

/** Service worker: offline page for navigations, cached build assets. Production only. */
export function registerServiceWorker() {
    if (!import.meta.env.PROD || !('serviceWorker' in navigator) || !window.isSecureContext) return;
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            /* the app works without it */
        });
    });
}
