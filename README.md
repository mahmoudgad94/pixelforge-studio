# Pixelforge Studio

Take-home for the PromptEdit Senior Product Engineer role: a small Laravel + Vue + Inertia app where users generate images through the Pixelforge API and pay for them with credits (Pixelforge cost + 25%).

**Live:** _URL here_ · **Stack:** Laravel 12, Vue 3, Inertia 2, Tailwind, Postgres (SQLite locally), database queue.

## What it does

- **Generate** (`/generate`): prompt, size, quality, up to 4 reference image URLs. The price estimate updates as you type: an expected price plus the min/max range, with a token breakdown.
- **History** (`/history`): live status (sending, queued, processing, succeeded, failed), estimated and actual price, the image, and a clear message once Pixelforge's 24h image link has expired. The page polls only while something is in flight.
- **Credits** (stretch): per-user balance, fake "Buy $10 credits", and generations the user can't afford are blocked.
- **Admin report** (stretch, `/admin/report`): credit spend, provider cost and profit per UTC day and per user, with every day checked against Pixelforge `GET /v1/usage` (count and cost must match exactly, a mismatch is flagged).

## The parts that matter: money

**No floats anywhere.** All money is integer **picodollars** (1 USD = 10^12). Pixelforge prices to 9 decimals; adding 25% needs 2 more. Picodollars keep every cost and every price exact as a plain `BIGINT`, so sums in reports are exact too. Decimal maths uses `brick/math`. See `app/Support/Money.php`.

**Actual cost** = Σ tokens × rate / 1M, computed from the generation's own `usage` and the `rates` frozen on that generation (not today's rates). `app/Pixelforge/Pricing.php`. Unit tests reproduce both worked examples from the docs to the nanodollar.

**User price** = cost × 1.25, rounded **up** to the next picodollar (never undercharge). With Pixelforge's current rates it is always exact, so no rounding actually happens. Sub-cent amounts are charged exactly; nothing is rounded to cents.

**Estimate.** Text tokens are only known within ±15% and reasoning tokens within a per-quality range, so the UI shows expected / min / max. The same formula runs in PHP (authoritative) and in TypeScript with `BigInt` (live preview).

**Hold, then charge.** On submit we hold the worst-case price (so a balance can never go negative), then on success release the hold and charge the exact price; on failure just release it. Failed generations cost nothing.

**Never twice.** Every balance change is a row in `credit_transactions` with a unique index on `(generation_id, type)`: a generation can be held, released and charged at most once, enforced by the database, not just by code. All settlement happens in one transaction with row locks on the generation and the user.

## Talking to Pixelforge

- **Idempotent submission.** Each generation gets a UUID that is sent as `Idempotency-Key` and as `metadata.generation_uuid`. Submitting happens in a queued job (`SubmitGeneration`), so a timeout or a worker crash can be retried without ever creating a second generation.
- **Rate limits, concurrency limits, overloads** (429 / 503): retried in the job with `Retry-After` + exponential backoff + jitter, capped. Permanent errors (content policy, validation, daily limit) fail the generation with a clear message and release the hold.
- **Giving up is not final.** If we stop retrying, the generation is marked as a *local* failure. If Pixelforge did create it after all, the webhook or the reconciler still records it and charges correctly.

## Webhooks (`POST /webhooks/pixelforge`)

1. Verify `Pixelforge-Signature` (HMAC-SHA256 of `t.rawBody`, constant-time compare, 5 minute tolerance) against the raw body before decoding. Excluded from CSRF.
2. Store the event by its `evt_` id (primary key). A duplicate of a processed event is acknowledged and ignored.
3. Find our row by `metadata.generation_uuid`, so a webhook that arrives **before** the POST response returns still matches.
4. `GenerationSync::apply()` locks the row and only moves **forward** (queued → processing → succeeded/failed). Late or out-of-order events can fill in missing timestamps but never move a status back or change a final state. Money is settled in the same transaction.

**Missed webhooks:** `generations:reconcile` runs every minute and polls anything in flight for over 60s (≤50 per run, under the 60 req/min limit). It also re-dispatches pending rows whose job was lost.

## Admin MCP server (extra)

`POST /mcp/admin` is an MCP server (Laravel MCP) so an admin can ask an AI agent things like "what was our profit last week?" or "refund Ana $2 for the broken image".

- **Read tools:** `get-spend-report`, `find-generations`, `get-user-credits`, `get-action-status`.
- **Write tools need a human:** `request-credit-adjustment` never touches a balance. It creates a pending request; an admin approves or rejects it in **Admin > Approvals**. Requests expire after 24h, can't be approved twice (row lock + status check) and can't push a balance below zero. Every applied change is a ledger row (`adjustment`).
- **Auth:** OAuth sign-in (Laravel Passport, with discovery and dynamic client registration from `Mcp::oauthRoutes()`), or a static bearer token per admin stored as a SHA-256 hash. Only admins (`users.is_admin`) can authorize or call it; tools act as that admin. Throttled to 60 requests/minute.

### Connect Claude to the admin MCP

- **claude.ai / Claude Desktop:** Settings > Connectors > Add custom connector, paste `https://<host>/mcp/admin`, then sign in with an admin account and click **Authorize**.
- **Claude Code:** `claude mcp add --transport http pixelforge https://<host>/mcp/admin`, then run `/mcp` and pick `pixelforge` to sign in.
- **Static token (fallback, no browser):** `php artisan mcp:token you@example.com`, then `claude mcp add --transport http pixelforge https://<host>/mcp/admin --header "Authorization: Bearer <token>"`.

OAuth needs signing keys: `php artisan passport:keys` writes `storage/oauth-*.key` (gitignored). In production set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` instead (the container generates key files on boot if they are missing, but they would change on every redeploy without a volume and sign everyone out).

I've built the same pattern (read tools + approval-gated writes) for client operations dashboards in production; this is a small version of it.

## Report correctness

Pixelforge bills on the UTC day a generation **completed**, so the report groups by `completed_at` in UTC as well. That is what makes the per-day provider cost line up exactly with `GET /v1/usage`. Use one Pixelforge API key per environment so the usage report only contains this app's generations.

## Run locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET (from the Pixelforge dashboard)
php artisan migrate && php artisan passport:keys
composer run dev            # web + queue + vite
php artisan schedule:work   # reconciler
# webhooks need a public URL: e.g. `cloudflared tunnel --url http://localhost:8000`
```

Make yourself admin: `php artisan tinker --execute="App\Models\User::where('email','you@x.com')->update(['is_admin'=>true])"`

## Tests

```bash
php artisan test
```

Covers: admin MCP auth (static token, full OAuth flow, admins only, OAuth discovery), read tools, approval-gated writes (approve once, reject, expiry, no negative balance), docs pricing examples to the nanodollar, Unicode token estimate, markup rounding, hold/charge, insufficient credits, duplicate webhooks (same and different event ids), out-of-order events, webhook-before-response, failed = free, bad and stale signatures, idempotency header, content-policy rejection, and recovery from a local give-up.

## Deploy

One image, three roles chosen by the `ROLE` env var (`docker/start.sh`):

| Service | `ROLE` | Does |
|---|---|---|
| web | `web` | FrankenPHP HTTP server; runs migrations on boot |
| worker | `worker` | queue worker that submits generations to Pixelforge |
| scheduler | `scheduler` | every-minute reconciler for missed or late webhooks |

Separate services mean a slow Pixelforge call never holds up a page, and each one restarts or scales on its own. `ROLE=all` (the default) runs all three in one container for single-service hosts. `railway.json` builds the Dockerfile and always restarts a service that exits (the worker recycles itself hourly).

Every service needs the same `DB_URL` (Postgres), `APP_KEY`, `APP_URL`, `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` (contents of the key files, so all services sign and check OAuth tokens with one pair). Set the webhook URL in Pixelforge to `https://<host>/webhooks/pixelforge`.

## With more time

- Concurrency: queue submissions per account with a Redis funnel (3 slots) instead of relying on 429 + retry.
- Copy images to our own storage (S3) on success, so history doesn't lose them after 24h.
- Prune the `webhook_events` table; alert when a reconciler run finds a webhook gap.
- Report: cache usage calls, CSV export.
