import { network, report } from '@/lib/network';

/**
 * Downloads a file from our own server. The link still works as a plain link without JavaScript;
 * with it, a failure shows in the request-problem toast instead of replacing the page with an error.
 */
export async function downloadFile(event: Event, url: string) {
    event.preventDefault();
    if (!network.online) return report('You are offline. Try the download again when you are back online.');
    try {
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'image/*,application/json' } });
        if (!res.ok) {
            const body = await res.json().catch(() => null);
            return report(body?.message ?? 'Could not download the image right now. Try again in a moment.');
        }
        const match = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') ?? '');
        const href = URL.createObjectURL(await res.blob());
        const a = document.createElement('a');
        a.href = href;
        a.download = match?.[1] ?? 'pixelforge-image';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(href), 10000);
    } catch {
        report('Could not reach the server. Try the download again in a moment.');
    }
}

/** 1.2 s, 45 s, 2 min 5 s. */
export function formatDuration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '–';
    if (ms < 1000) return ms < 1 ? '0 s' : `${ms} ms`;
    const s = Math.round(ms / 100) / 10;
    if (s < 60) return `${s} s`;
    const total = Math.round(ms / 1000);
    const m = Math.floor(total / 60);
    return `${m} min ${total % 60} s`;
}
