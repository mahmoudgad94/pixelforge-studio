import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { z } from 'zod';
import { fillGeneration, loginAsDemo, run, submitGeneration } from './helpers';

// Each test maps to a point in https://pixelforge.promptedit.dev/assessment.
// Prompts carry a run id so the agent can find the right card in History.

test('login: the demo account is pre-filled and signs straight in', async ({ app, screen }) => {
    await loginAsDemo({ app, screen });
});

test('generate: price estimate is shown before submitting and follows the options', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    await fillGeneration({ app, screen }, 'a red fox in the snow', 'low');
    await agent.assert('the estimated price panel shows a dollar amount with a min to max range and a token breakdown');
    const low = await agent.extract('the big estimated price amount, as shown', { schema: z.string() });
    await screen.getByRole('radio', /^high$/i).click();
    await agent.assert('the estimated price is now higher than {low}', { params: { low: String(low) } });
    await agent.act('add one reference image with URL {url}', { params: { url: 'https://example.com/style.png' } });
    await agent.assert('the price breakdown now counts reference image tokens (765 tokens)');
});

test('history: a succeeded generation shows status, estimate, actual charge and the image', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    const prompt = `e2e ${run()} a red fox, [succeed]`;
    await submitGeneration({ app, screen }, prompt, 'low');
    await expect(screen.getByText('History', { exact: false }).first()).toBeVisible();
    await agent.waitFor('the generation with prompt {prompt} shows status succeeded', {
        params: { prompt },
        timeout: 150_000,
        interval: 10_000,
        maxModelCalls: 20,
    });
    await agent.assert('the card for {prompt} shows an estimated price, a charged price that is not $0.00, and an image', {
        params: { prompt },
    });
});

test('failures are free: a [fail] generation ends failed and charges nothing', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    const prompt = `e2e ${run()} a broken robot, [fail]`;
    await submitGeneration({ app, screen }, prompt, 'low');
    await agent.waitFor('the generation with prompt {prompt} shows status failed', {
        params: { prompt },
        timeout: 150_000,
        interval: 10_000,
        maxModelCalls: 20,
    });
    await agent.assert('the card for {prompt} explains what went wrong and shows that nothing was charged ($0 or free)', {
        params: { prompt },
    });
});

test('content policy: a [blocked] prompt is rejected with a clear message and no charge', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    const prompt = `e2e ${run()} something, [blocked]`;
    await submitGeneration({ app, screen }, prompt, 'low');
    await agent.waitFor('the generation with prompt {prompt} shows status failed, or the page shows a content policy error', {
        params: { prompt },
        timeout: 90_000,
        interval: 5_000,
        maxModelCalls: 15,
    });
    await agent.assert('for {prompt} the user is told the prompt was rejected (content policy) and was not charged', {
        params: { prompt },
    });
});

test('webhooks: a [duplicate] generation succeeds and is charged once', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    const prompt = `e2e ${run()} a blue whale, [duplicate] [succeed]`;
    await submitGeneration({ app, screen }, prompt, 'low');
    await agent.waitFor('the generation with prompt {prompt} shows status succeeded', {
        params: { prompt },
        timeout: 150_000,
        interval: 10_000,
        maxModelCalls: 20,
    });
    await agent.assert('exactly one card exists for {prompt}, with a single charged amount', { params: { prompt } });
});

test('balances: buying credits adds exactly $10', async ({ app, screen, agent }) => {
    await loginAsDemo({ app, screen });
    const before = await agent.extract('the balance amount shown on the page, as shown', { schema: z.string() });
    await screen.getByRole('button', 'Buy $10 credits').click();
    await agent.waitFor('the balance is exactly $10 more than {before}', { params: { before: String(before) }, timeout: 20_000 });
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
