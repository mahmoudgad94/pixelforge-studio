import type { E2EConfig } from 'e2e';
import { claudeCode } from '@e2e-dev/acp';
import { web } from '@e2e-dev/web';

export default {
    tests: 'tests/e2e/**/*.e2e.ts',
    timeout: 240_000,
    workers: 1,
    agents: { default: { executor: claudeCode({ model: 'sonnet' }) } },
    targets: [{ engine: web(), app: { url: process.env.E2E_APP_URL ?? 'http://localhost:8000' } }],
} satisfies E2EConfig;
