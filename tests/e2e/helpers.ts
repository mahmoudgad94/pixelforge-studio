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
    await screen.getByRole('radio', new RegExp(`^${quality}$`, 'i')).click();
}

export async function submitGeneration(ctx: Ctx, prompt: string, quality: 'low' | 'medium' | 'high' = 'low') {
    await fillGeneration(ctx, prompt, quality);
    await ctx.screen.getByRole('button', 'Generate').click();
}

export const run = () => Date.now().toString(36);
