<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AuthorBanner from '@/components/AuthorBanner.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Code2, ExternalLink } from 'lucide-vue-next';

const page = usePage<SharedData>();

const repo = 'https://github.com/mahmoudgad94/pixelforge-studio';
const source = (path: string) => `${repo}/${path.endsWith('/') ? 'tree' : 'blob'}/main/${path.replace(/\/$/, '')}`;

type Shot = { src: string; alt: string; width: number; height: number; caption?: string };
type Section = { id: string; title: string; body: string[]; shots?: Shot[]; snippet?: string; code: string[] };

const shot = (name: string, alt: string, caption?: string, height = 1800): Shot => ({
    src: `/images/guide/${name}.webp`,
    alt,
    width: 2880,
    height,
    caption,
});

const sections: Section[] = [
    {
        id: 'estimate',
        title: 'See the price before you generate, pay the exact cost',
        body: [
            'The Generate page prices the prompt as you type: an expected price, the lowest and highest it can be, and the token breakdown behind it. The same formula runs in PHP, which bills, and in TypeScript with BigInt for the live preview.',
            'All money is stored as integer picodollars (1 USD = 10¹² picodollars). The final charge is the real token cost plus the platform fee (25% by default), to the fraction of a cent, with no rounding to cents.',
        ],
        shots: [
            shot(
                'estimate',
                'The Generate page with a prompt typed in and a live estimated price of $0.072306, a range of $0.058225 to $0.086388 and a token breakdown',
            ),
        ],
        code: ['app/Pixelforge/Pricing.php', 'resources/js/lib/pricing.ts', 'app/Support/Money.php'],
    },
    {
        id: 'test-prompts',
        title: 'Test prompt endings: [fail], [blocked], [duplicate], [slow]',
        body: [
            'The Pixelforge test API changes its behaviour when a prompt contains certain keywords, and the Generate page adds them with one click. [fail] fails after Pixelforge accepted it, [blocked] is refused by the content policy at submit time, [duplicate] sends every webhook twice, [slow] takes much longer, and [overloaded] answers 503 first so the app retries.',
            'Whatever happens, a failed image costs $0.00 and a duplicated one is charged once.',
        ],
        shots: [
            shot(
                'failed',
                'The detail page of a [fail] generation: status Failed, the reason "The image could not be generated", and a final charge of $0.00',
            ),
        ],
        code: ['resources/js/pages/Generations/Create.vue', 'app/Jobs/SubmitGeneration.php', 'tests/Feature/Pixelforge/BillingFlowTest.php'],
    },
    {
        id: 'credits',
        title: 'Buying credits',
        body: [
            'Every user has a credit balance. Buy credits opens a demo checkout with $5, $10 and $25 packs and shows the new balance before you confirm; no card is charged.',
            'Each purchase carries an idempotency key, so a double click adds credits once. A generation you cannot afford is stopped before it is sent.',
        ],
        shots: [
            shot('credits', 'The Buy credits dialog with the $10 pack selected, showing the current balance, the amount added and the new balance'),
        ],
        code: ['app/Http/Controllers/CreditController.php', 'resources/js/components/BuyCreditsDialog.vue', 'app/Pixelforge/Ledger.php'],
    },
    {
        id: 'history',
        title: 'History and the page for each image',
        body: [
            'History lists every image with a live status, the estimate next to the final charge and how long it took. It polls only while something is still in flight, and a browser notification says when an image is ready.',
            'Each image has its own page: your settings, the exact charge, a step by step timeline and the failure reason if there is one. Download fetches the file through the server and names it after the prompt. Pixelforge keeps images for 24 hours, and an expired link says so.',
        ],
        shots: [
            shot(
                'history',
                'The History page: one image still generating, finished images with their estimated and charged prices, Download and Details buttons, and failed cards',
            ),
            shot(
                'detail',
                'The detail page of one image: the picture, the prompt, quality and size, the estimated and final price, and a Download button',
            ),
        ],
        code: [
            'resources/js/pages/Generations/Index.vue',
            'resources/js/pages/Generations/Show.vue',
            'app/Reports/GenerationProgress.php',
            'app/Http/Controllers/GenerationController.php',
        ],
    },
    {
        id: 'report',
        title: 'Admin spend report, checked against Pixelforge',
        body: [
            'The admin report shows credit spend, provider cost and profit per UTC day and per user. Days are the UTC day a generation completed, which is how Pixelforge bills.',
            'Each day is compared with Pixelforge’s GET /v1/usage: the count, the exact cost and the token counts must all be equal, or the day is flagged. Totals are summed in SQL and the usage call is cached.',
        ],
        shots: [
            shot(
                'report',
                'The admin report: totals for generations, credit spend, provider cost and profit, and a per-day table where every day shows Match against Pixelforge',
            ),
        ],
        code: [
            'app/Reports/SpendReport.php',
            'app/Reports/UsageCache.php',
            'resources/js/pages/Admin/Report.vue',
            'tests/Feature/Reports/SpendReportTest.php',
        ],
    },
    {
        id: 'timeline',
        title: 'Every call for one generation',
        body: [
            'An admin can open any generation and see how long each stage took, the tokens Pixelforge billed, the cost and the profit, and every API call and webhook in order: HTTP status, duration, delivery attempt, sender and what the app answered.',
            'In this example the [duplicate] webhook shows up as received and ignored. Each row expands to the request and response, stored with secrets removed.',
        ],
        shots: [
            shot(
                'timeline',
                'The admin timeline of one generation: stage durations, billed tokens, and a table of the API call and four webhooks, one marked duplicate',
                undefined,
                2814,
            ),
        ],
        code: ['app/Reports/GenerationTimeline.php', 'app/Models/PixelforgeActivity.php', 'resources/js/pages/Admin/Generation.vue'],
    },
    {
        id: 'webhooks',
        title: 'Webhooks: verified, and charged exactly once',
        body: [
            'POST /webhooks/pixelforge checks the Pixelforge-Signature (HMAC-SHA256 of the raw body, constant-time compare, 5 minute window) before it decodes anything. Each event is stored by its id, so a repeated delivery is acknowledged and ignored.',
            'The generation row is locked and only ever moves forward, so a late or out of order event cannot undo a final state. Every balance change is a ledger row with a unique (generation_id, type) index: the database itself refuses a second charge.',
        ],
        code: [
            'app/Http/Controllers/PixelforgeWebhookController.php',
            'app/Pixelforge/WebhookSignature.php',
            'app/Pixelforge/GenerationSync.php',
            'database/migrations/2026_11_16_000003_create_credit_transactions_table.php',
        ],
    },
    {
        id: 'reconciler',
        title: 'The reconciler',
        body: [
            'If a webhook never arrives, generations:reconcile catches it. It runs every minute, asks Pixelforge about up to 50 in-flight generations that have had no update for over a minute (well under the 60 requests a minute limit) and applies the real state.',
            'It also resends pending generations whose queue job was lost. The generation’s UUID is the Idempotency-Key, so a resend can never create a second image.',
        ],
        code: ['app/Console/Commands/ReconcileGenerations.php', 'routes/console.php'],
    },
    {
        id: 'mcp',
        title: 'Admin MCP server with human approval',
        body: [
            '/mcp/admin is an MCP server, so an admin can ask Claude or another MCP app about spend, generations, user credits and timelines. Reading is answered directly.',
            'Changing a balance is not: the AI can only file a request, which waits under Approvals until an admin approves or rejects it. Requests expire after 24 hours, cannot be approved twice and cannot push a balance below zero. Sign-in is OAuth or a per-admin token stored as a hash.',
        ],
        shots: [
            shot(
                'approvals',
                'The Approvals page: a $1.50 credit request from an AI assistant waiting with Approve and Reject buttons, and the history of earlier requests',
            ),
        ],
        code: ['app/Mcp/AdminServer.php', 'app/Mcp/Tools/', 'app/Pixelforge/AdminActions.php', 'resources/js/pages/Admin/Approvals.vue'],
    },
    {
        id: 'api',
        title: 'JSON admin API',
        body: [
            'The same admin data is available as JSON under /api/admin, with the same bearer tokens and a limit of 60 requests a minute: the spend report, the generations of one day, one generation’s timeline, the MCP call log and the credit requests.',
        ],
        snippet:
            'curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \\\n  "https://<host>/api/admin/report?start_date=2026-11-10&end_date=2026-11-16"',
        code: ['routes/api.php', 'app/Http/Controllers/Api/AdminApiController.php', 'docs/admin-api.md'],
    },
    {
        id: 'offline',
        title: 'Offline handling and skeleton screens',
        body: [
            'When the connection drops, a banner says so, the form keeps what you typed and sending waits until you are back; then the page refreshes its data. A failed request gets a plain message instead of an error page, and a service worker shows an offline page instead of the browser error.',
            'A page change that takes longer than 120 ms shows a skeleton shaped like the page that is opening.',
        ],
        shots: [
            shot(
                'offline',
                'The Generate page while offline: an orange banner says you are offline, the typed prompt is kept and the button reads Offline',
            ),
            shot('skeleton', 'A skeleton screen in the shape of the History page, shown while it loads'),
        ],
        code: [
            'resources/js/lib/network.ts',
            'resources/js/components/ConnectionStatus.vue',
            'resources/js/components/PageSkeleton.vue',
            'public/sw.js',
        ],
    },
    {
        id: 'tests',
        title: 'Tests and CI',
        body: [
            'The PHPUnit suite covers pricing to the nanodollar, hold and charge, duplicate and out of order webhooks, bad and stale signatures, the content-policy path, the report check against usage, the MCP OAuth flow and the approvals.',
            'An end-to-end suite runs the same money scenarios against a real deployment. GitHub Actions run the tests, Pint, Prettier and ESLint on every push.',
        ],
        code: ['tests/', 'tests/e2e/assessment.e2e.ts', '.github/workflows/tests.yml', '.github/workflows/lint.yml'],
    },
    {
        id: 'deploy',
        title: 'Deployed on Railway',
        body: [
            'One Docker image (FrankenPHP) runs in every role, chosen by the ROLE variable: web (runs migrations on boot), worker (sends generations to Pixelforge), scheduler (the reconciler), background (worker and scheduler together, used on Railway’s free plan) or all.',
            'Every service shares the Postgres database, the app key, the Pixelforge keys and one OAuth key pair. Errors go to Sentry when a DSN is set.',
        ],
        code: ['Dockerfile', 'docker/start.sh', 'README.md'],
    },
];

const navLabels: Record<string, string> = {
    estimate: 'Price and billing',
    'test-prompts': 'Test prompts',
    credits: 'Credits',
    history: 'History and Download',
    report: 'Spend report',
    timeline: 'Generation timeline',
    webhooks: 'Webhooks',
    reconciler: 'Reconciler',
    mcp: 'MCP and approvals',
    api: 'JSON API',
    offline: 'Offline and skeletons',
    tests: 'Tests and CI',
    deploy: 'Railway deploy',
};

const billingSteps = [
    { title: 'Estimate', body: 'Before sending, the worst-case price is worked out from the prompt, size, quality and reference images.' },
    { title: 'Hold', body: 'That worst case is held in one transaction with the user row locked, so a balance can never go negative.' },
    { title: 'Submit once', body: 'A queued job sends it with the generation’s UUID as Idempotency-Key, so retries never create a second image.' },
    {
        title: 'Settle',
        body: 'Whichever reports the final state first, webhook or reconciler: on success the hold is released and the exact token cost plus fee is charged; on failure the hold is just released.',
    },
    {
        title: 'Never twice',
        body: 'A unique (generation_id, type) index on the ledger lets each generation be held, released and charged at most once.',
    },
    { title: 'Check', body: 'The admin report compares every day with Pixelforge’s own usage numbers and flags any difference.' },
];
</script>

<template>
    <Head title="Product tour">
        <meta
            name="description"
            content="A tour of Pixelforge Studio: exact image pricing, credits, history, an admin report checked against the provider, webhooks, an MCP admin server and more."
        />
    </Head>

    <div class="min-h-svh overflow-x-hidden bg-background text-foreground">
        <AuthorBanner />

        <header class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-5 md:px-6">
            <Link :href="route('home')" class="flex min-w-0 items-center gap-2 font-semibold">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground"
                    ><AppLogoIcon class="size-5"
                /></span>
                <span class="truncate">Pixelforge Studio</span>
            </Link>
            <Button v-if="page.props.auth.user" as-child size="sm"
                ><Link :href="route('generations.create')">Open studio <ArrowRight class="size-4" /></Link
            ></Button>
            <Button v-else as-child size="sm" variant="outline"><Link :href="route('login')">Sign in</Link></Button>
        </header>

        <main>
            <section class="mx-auto max-w-3xl px-4 pb-12 pt-6 text-center md:px-6 md:pt-12">
                <Badge variant="outline" class="font-normal">Product tour</Badge>
                <h1 class="mt-5 text-3xl font-semibold tracking-tight sm:text-4xl md:text-5xl">How Pixelforge Studio works</h1>
                <p class="mx-auto mt-4 max-w-2xl text-base text-muted-foreground sm:text-lg">
                    A small app that sells AI image generations through the Pixelforge API and bills each one at its exact cost. Here is every
                    feature, with screenshots and links to the code.
                </p>
                <div class="mt-7 flex flex-wrap justify-center gap-3">
                    <Button as-child size="lg">
                        <a href="/login">Open the app <ArrowRight class="size-4" /></a>
                    </Button>
                    <Button as-child size="lg" variant="outline">
                        <a :href="repo" target="_blank" rel="noopener"><Code2 class="size-4" /> View the code</a>
                    </Button>
                </div>
                <p class="mt-4 text-sm text-muted-foreground">The sign in page has a demo account filled in. Screenshots use demo data.</p>
            </section>

            <nav aria-label="Sections" class="border-y bg-muted/30">
                <ul class="mx-auto flex max-w-6xl flex-wrap justify-center gap-2 px-4 py-4 md:px-6">
                    <li v-for="s in sections" :key="s.id">
                        <a
                            :href="`#${s.id}`"
                            class="inline-block rounded-full border bg-background px-3 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                            >{{ navLabels[s.id] ?? s.title }}</a
                        >
                    </li>
                    <li>
                        <a
                            href="#billing"
                            class="inline-block rounded-full border bg-background px-3 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                            >How billing stays correct</a
                        >
                    </li>
                </ul>
            </nav>

            <div class="mx-auto max-w-5xl px-4 md:px-6">
                <section v-for="(s, i) in sections" :id="s.id" :key="s.id" class="scroll-mt-6 border-b py-12 last:border-b-0 md:py-16">
                    <div class="text-xs font-medium tabular-nums text-muted-foreground">{{ String(i + 1).padStart(2, '0') }}</div>
                    <h2 class="mt-1 text-2xl font-semibold tracking-tight">{{ s.title }}</h2>
                    <div class="mt-3 max-w-3xl space-y-3 text-muted-foreground">
                        <p v-for="(p, j) in s.body" :key="j">{{ p }}</p>
                    </div>

                    <pre
                        v-if="s.snippet"
                        class="mt-5 max-w-full overflow-x-auto rounded-lg border bg-muted/50 p-4 text-xs leading-relaxed"
                    ><code>{{ s.snippet }}</code></pre>

                    <div v-if="s.shots" class="mt-6 grid gap-5" :class="s.shots.length > 1 ? 'lg:grid-cols-2' : ''">
                        <figure v-for="img in s.shots" :key="img.src" class="min-w-0">
                            <a :href="img.src" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border bg-muted shadow-sm">
                                <img
                                    :src="img.src"
                                    :alt="img.alt"
                                    :width="img.width"
                                    :height="img.height"
                                    loading="lazy"
                                    decoding="async"
                                    class="h-auto w-full"
                                />
                            </a>
                            <figcaption v-if="img.caption" class="mt-2 text-sm text-muted-foreground">{{ img.caption }}</figcaption>
                        </figure>
                    </div>

                    <p class="mt-5 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                        <span class="font-medium">Code:</span>
                        <a
                            v-for="c in s.code"
                            :key="c"
                            :href="source(c)"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex min-w-0 items-center gap-1 break-all font-mono text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >{{ c }}<ExternalLink class="size-3 shrink-0"
                        /></a>
                    </p>
                </section>
            </div>

            <section id="billing" class="scroll-mt-6 border-t bg-muted/30">
                <div class="mx-auto max-w-5xl px-4 py-14 md:px-6">
                    <h2 class="text-2xl font-semibold tracking-tight">How billing stays correct</h2>
                    <p class="mt-2 max-w-3xl text-muted-foreground">The path one generation takes through the money code.</p>
                    <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <li v-for="(b, i) in billingSteps" :key="b.title" class="rounded-xl border bg-card p-5">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground"
                                    >{{ i + 1 }}</span
                                >
                                <h3 class="font-semibold">{{ b.title }}</h3>
                            </div>
                            <p class="mt-2 text-sm text-muted-foreground">{{ b.body }}</p>
                        </li>
                    </ol>
                    <p class="mt-6 text-sm">
                        <span class="font-medium">Code:</span>
                        <a
                            :href="source('app/Pixelforge/Ledger.php')"
                            target="_blank"
                            rel="noopener"
                            class="ml-2 font-mono text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >app/Pixelforge/Ledger.php</a
                        >
                    </p>
                </div>
            </section>

            <section class="mx-auto max-w-3xl px-4 py-16 text-center md:px-6">
                <h2 class="text-2xl font-semibold tracking-tight">Try it yourself</h2>
                <p class="mt-2 text-muted-foreground">The demo account is an admin, so the report and approvals are open too.</p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <Button as-child size="lg"
                        ><a href="/login">Open the app <ArrowRight class="size-4" /></a
                    ></Button>
                    <Button as-child size="lg" variant="outline"
                        ><a :href="repo" target="_blank" rel="noopener"><Code2 class="size-4" /> View the code</a></Button
                    >
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm text-muted-foreground md:px-6">
                <span>Pixelforge Studio. Take-home project, Pixelforge is a test API.</span>
                <a href="https://mahmoudebaa.com" target="_blank" rel="noopener" class="hover:text-foreground">Made by Mahmoud Ebaa</a>
            </div>
        </footer>
    </div>
</template>
