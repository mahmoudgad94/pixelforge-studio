import { expect } from 'e2e';

type Ctx = { app: { open(path: string): Promise<void> }; screen: any };

/** Logs in with the demo account the login page pre-fills (DEMO_LOGIN_* in .env). */
export async function loginAsDemo({ app, screen }: Ctx) {
    await app.open('/login');
    await expect(screen.getByText('Demo account, already filled in')).toBeVisible();
    await screen.getByRole('button', 'Log in').click();
    await expect(screen.getByText('Estimated price')).toBeVisible({ timeout: 15_000 });
}

/** Opens the generate page, fills the prompt and picks size and quality. */
export async function fillGeneration({ app, screen }: Ctx, prompt: string, quality: 'low' | 'medium' | 'high' = 'low') {
    await app.open('/generate');
    await screen.getByPlaceholder('A lighthouse on a cliff at sunset, cinematic lighting').fill(prompt);
    await screen.getByRole('button', new RegExp(`^${quality}$`, 'i')).click();
}

export async function submitGeneration(ctx: Ctx, prompt: string, quality: 'low' | 'medium' | 'high' = 'low') {
    await fillGeneration(ctx, prompt, quality);
    await ctx.screen.getByRole('button', 'Generate').click();
}

export const run = () => Date.now().toString(36);

/** Reads a dollar amount shown on the page (e.g. "$10.017641") as an exact number of picodollars. */
export async function readUsd(screen: any, testId: string): Promise<number> {
    const loc = screen.getByTestId(testId);
    // The balance carries its exact picodollars (the label is rounded); other amounts are read from the label.
    const picos = await loc.getAttribute('data-picos');
    // Picodollars stay well under 2^53 for any balance here, so a plain number is exact.
    return picos !== null ? Number(picos) : usdToPicos((await loc.textContent()) ?? '');
}

const PICOS = 10n ** 12n;

/** "$10.017641" or "10.017641" as an exact number of picodollars. */
export function usdToPicos(value: string): number {
    const [whole, frac = ''] = value.replace(/[^0-9.]/g, '').split('.');
    return Number(BigInt(whole || '0') * PICOS + BigInt((frac + '0'.repeat(12)).slice(0, 12)));
}

/**
 * Reloads History until the card for `prompt` reaches a final status (webhooks and the
 * reconciler update it server side), then returns that status and the card's text.
 */
export async function waitForFinal({ app, screen }: Ctx, prompt: string, timeout = 150_000) {
    const card = () => screen.getByTestId(`generation:${prompt}`);
    let status: string | null = null;
    await expect
        .poll(
            async () => {
                await app.open('/history');
                try {
                    status = await card().getAttribute('data-status');
                } catch {
                    status = null;
                }
                return status === 'succeeded' || status === 'failed';
            },
            { timeout, interval: 5_000, message: `generation "${prompt}" never reached a final status (last: ${status})` },
        )
        .toBe(true);

    return { status: status as 'succeeded' | 'failed', text: (await card().textContent()) ?? '', card: card() };
}

/** The exact charge on a succeeded card (data-exact holds the full precision, the label is rounded). */
export async function exactCharge(screen: any, prompt: string): Promise<number> {
    return usdToPicos((await screen.getByTestId(`charged:${prompt}`).getAttribute('data-exact')) ?? '');
}
