import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { fillGeneration, loginAsDemo, readUsd, run, submitGeneration, exactCharge, waitForFinal } from './helpers';

// Each test maps to a point in https://pixelforge.promptedit.dev/assessment.
// Prompts carry a run id so each test finds its own card in History (data-testid="generation:<prompt>").

test('home: public landing page with real prices links into the app', async ({ app, screen, agent }) => {
    await app.open('/');
    await expect(screen.getByText('See the price', { exact: false }).first()).toBeVisible();
    await agent.assert('the page shows example prices in US dollars and a link to sign in or open the studio');
    await agent.assert('the header logo is a Pixelforge Studio mark, not the Laravel logo');
});

test('navigation: every sidebar link opens its page', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    for (const name of ['History', 'Admin report', 'Approvals', 'Generate']) {
        await agent.act('click the {name} link in the sidebar', { params: { name } });
        await agent.assert('the {name} page is open (its heading or breadcrumb says so) and shows no error', { params: { name } });
    }
});

test('login: the demo account is pre-filled and signs straight in', async ({ app, screen }) => {
    await loginAsDemo({ app, screen });
});

test('generate: price estimate is shown before submitting and follows the options', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    await fillGeneration({ app, screen }, 'a red fox in the snow', 'low');
    await agent.assert('the estimated price panel shows a dollar amount with a min to max range and a token breakdown');
    const low = await readUsd(screen, 'estimated-price');
    await screen.getByRole('button', /^high$/i).click();
    expect(await readUsd(screen, 'estimated-price')).toBeGreaterThan(low);
    await agent.act('add one reference image with URL {url}', { params: { url: 'https://example.com/style.png' } });
    await agent.assert('the price breakdown now counts reference image tokens (765 tokens)');
});

test('history: a succeeded generation shows status, estimate, actual charge and the image', async ({ app, screen }) => {
    await loginAsDemo({ app, screen });
    const before = await readUsd(screen, 'balance');
    const prompt = `e2e ${run()} a red fox, [succeed]`;
    await submitGeneration({ app, screen }, prompt, 'low');

    const { status, text } = await waitForFinal({ app, screen }, prompt);
    expect(status).toBe('succeeded');
    expect(text).toMatch(/Estimated\s*\$\d/);
    await expect(screen.getByRole('img', prompt)).toBeVisible();

    const charged = await exactCharge(screen, prompt);
    expect(charged).toBeGreaterThan(0);

    // The balance went down by exactly the actual charge (the reserve was settled, not kept).
    await app.open('/generate');
    const after = await readUsd(screen, 'balance');
    expect(before - after).toBe(charged);
});

test('failures are free: a [fail] generation ends failed and charges nothing', async ({ app, screen }) => {
    await loginAsDemo({ app, screen });
    const before = await readUsd(screen, 'balance');
    const prompt = `e2e ${run()} a broken robot, [fail]`;
    await submitGeneration({ app, screen }, prompt, 'low');

    const { status, text } = await waitForFinal({ app, screen }, prompt);
    expect(status).toBe('failed');
    expect(text).toContain('$0.00');
    expect(text).toMatch(/Retry/);

    await app.open('/generate');
    expect(await readUsd(screen, 'balance')).toBe(before);
});

test('content policy: a [blocked] prompt is rejected with a clear message and no charge', async ({ app, screen }) => {
    await loginAsDemo({ app, screen });
    const before = await readUsd(screen, 'balance');
    const prompt = `e2e ${run()} something, [blocked]`;
    await submitGeneration({ app, screen }, prompt, 'low');

    const { status, text } = await waitForFinal({ app, screen }, prompt, 90_000);
    expect(status).toBe('failed');
    expect(text.toLowerCase()).toMatch(/policy|blocked|not allowed/);
    expect(text).toContain('$0.00');

    await app.open('/generate');
    expect(await readUsd(screen, 'balance')).toBe(before);
});

test('webhooks: a [duplicate] generation succeeds and is charged once', async ({ app, screen }) => {
    await loginAsDemo({ app, screen });
    const before = await readUsd(screen, 'balance');
    const prompt = `e2e ${run()} a blue whale, [duplicate] [succeed]`;
    await submitGeneration({ app, screen }, prompt, 'low');

    const { status } = await waitForFinal({ app, screen }, prompt);
    expect(status).toBe('succeeded');

    // Give Pixelforge time to send the duplicate deliveries, then the balance must have moved by one charge only.
    await new Promise((r) => setTimeout(r, 20_000));
    await app.open('/history');
    const charged = await exactCharge(screen, prompt);
    await app.open('/generate');
    const spent = before - (await readUsd(screen, 'balance'));
    expect(charged).toBeGreaterThan(0);
    expect(spent).toBe(charged); // one charge, not two
});

test('balances: buying credits asks for confirmation and adds exactly the chosen pack', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    const before = await readUsd(screen, 'balance');
    await screen.getByRole('button', 'Buy credits').click();
    await agent.assert('a confirmation dialog shows the current balance and the balance after buying');
    await screen.getByRole('button', 'Cancel').click();
    expect(await readUsd(screen, 'balance')).toBe(before);
    await screen.getByRole('button', 'Buy credits').click();
    await screen.getByRole('button', /^\$5$/).click();
    await screen.getByRole('button', /Confirm \$5 purchase/).click();
    await expect(screen.getByText('Added $5.00 of credits.', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
    expect(await readUsd(screen, 'balance')).toBe(before + 5 * 10 ** 12);
});

test('balances: a new user with no credits cannot generate', async ({ app, screen, agent }) => {
    await app.open('/register');
    await agent.act('sign up with name {name}, email {email} and password {password} (both password fields)', {
        params: { name: 'E2E Broke', email: `broke-${run()}@e2e.test`, password: 'e2e-password-123' },
    });
    await fillGeneration({ app, screen }, 'a lighthouse at dawn', 'high');
    await agent.assert('the user cannot generate: the balance is $0 and the Generate button is disabled or a not-enough-credits message is shown');
});

test('admin report: spend, provider cost and profit per day and per user, checked against Pixelforge usage', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    await app.open('/admin/report');
    await agent.assert('the report shows per-day rows with credit spend, provider cost and profit');
    await agent.assert('the report shows a per-user breakdown');
    await agent.assert('each day shows whether it matches Pixelforge usage, and no day is flagged as a mismatch');
});
