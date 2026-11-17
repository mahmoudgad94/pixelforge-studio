# Pixelforge integration notes

How this app integrates the (simulated) Pixelforge image API, written as a hand-in for the assessment.

Sources:

- Pixelforge's own docs at <https://pixelforge.promptedit.dev/docs> (read 2026-11-17). See "How I read the docs" below, because the page is not plain HTML.
- Public `GET /v1/pricing`, called with curl. It matches `Pricing::defaultTable()` exactly (`app/Pixelforge/Pricing.php:33`).
- This repository. Every claim about our behaviour has a `file:line` reference or a test name. Line numbers are against commit `db15b45`.

Anything marked **Unverified** or **Assumption** is something I could not confirm from the docs, the code or a real call. I did not have a Pixelforge API key while writing this, so I made no authenticated calls. Behaviour of the real API under load, and the exact shape of a `ping` body, are therefore not observed by me.

## How I read the docs

`/docs` returns a Vue/Inertia shell. The prose is compiled into a JavaScript chunk, so `curl` on the page gives HTML with almost no text. I read the text from the page's embedded JSON props (rates, limits, retry delays, test keywords) plus the `Docs-*.js` chunk it loads. There is no OpenAPI file: `/v1/openapi.json` and `/v1/docs` both return a JSON 404 (`{"error":{"type":"invalid_request_error","code":"not_found","message":"No such resource."}}`). If the chunk name changes the text moves with it, so for a human reader the rendered page is the reference.

Facts taken from the docs page:

| Topic | What the docs say |
| --- | --- |
| Create | `POST /v1/generations` returns `202` with a generation in `queued`. Unknown parameters are rejected with `422`. |
| Idempotency | `Idempotency-Key`, any string up to 255 chars, scoped to the account, never expires. Same key and same parameters returns the same generation as it is now with `202` and `Idempotent-Replayed: true`. Same key with different parameters returns `409 idempotency_key_reused`. |
| Metadata | Up to 20 string keys, values up to 500 chars, returned on the generation and in every webhook. |
| Limits | 60 requests/minute, 3 concurrent generations, 500 generations per UTC day. `429` with `Retry-After`. Concurrency limit: `Retry-After: 5`. Overloaded: `503`, `Retry-After: 2`. |
| Webhooks | HMAC-SHA256 over `<t>.<raw body>`, header `Pixelforge-Signature: t=...,v1=...`. Any `2xx` within 10 s is delivered. Otherwise retries after 10 s, 30 s, 2 m, 10 m, 15 m (6 attempts total). Redirects are not followed. At-least-once, any order, can arrive before the POST returns. |
| Events | `generation.processing`, `generation.succeeded`, `generation.failed`, `ping` (dashboard "Send test event"). |
| Pricing | Per-token rates in USD per 1M tokens, frozen on the generation (`rates`). The API never returns a price. Exact decimals, "never rounded". |
| Usage | `GET /v1/usage`, per UTC day, billed on the day a generation **completed**, 92 days max, `cost_usd` with 9 decimals. |
| Images | Signed PNG URL, expires 24 h after completion. A "fresh" URL from `GET` expires at the same time. |

## 1. How each documented rule is met

### Idempotency key

- The local UUID of the generation is sent as the `Idempotency-Key` header: `app/Pixelforge/PixelforgeClient.php:96` (called from `app/Jobs/SubmitGeneration.php:57`).
- The UUID is created once per generation row and is unique in the database (`database/migrations/2026_11_16_000002_create_generations_table.php:15`), so every retry of the job, and every re-dispatch by the reconciler, sends the same key with the same body.
- A replay is logged with outcome `replayed` when `Idempotent-Replayed: true` is present (`PixelforgeClient.php:59`). A replay response is passed to `GenerationSync::apply()` like any other response, so a replay that already says `succeeded` settles the money straight away (`SubmitGeneration.php:69-70`).
- The client accepts any `2xx` as success (`PixelforgeClient.php:99`). The docs say a create and a replay both return `202`. The check is deliberately looser than the docs.
- Tests: `BillingFlowTest::test_submit_job_uses_idempotency_key_and_applies_response` (`tests/Feature/Pixelforge/BillingFlowTest.php:252`) and the replay in `test_accepted_but_not_recorded_is_recovered_by_the_reconciler_without_a_duplicate` (`:199`).

### `metadata.generation_uuid`

- Sent on create together with `user_id` (cast to a string because the docs say metadata values are strings): `SubmitGeneration.php:53-56`.
- It is how a webhook finds our row even when we never stored Pixelforge's id: `GenerationSync::findLocal()` matches `metadata.generation_uuid` first and only falls back to `pixelforge_id` when there is no uuid (`app/Pixelforge/GenerationSync.php:25-33`). The webhook controller calls it before anything else (`app/Http/Controllers/PixelforgeWebhookController.php:59`).
- Test: `test_webhook_before_post_response_is_matched_by_metadata` (`BillingFlowTest.php:152`).

### Webhook signature

- The raw body is read before any JSON decoding (`PixelforgeWebhookController.php:19`) and verified before anything else happens (`:37-41`).
- `WebhookSignature::verify()` (`app/Pixelforge/WebhookSignature.php:10-44`): parses `t=` and `v1=`, rejects if `|now - t| > 300 s` (`:31`), computes `hash_hmac('sha256', "{t}.{body}", secret)` (`:35`) and compares with `hash_equals` (constant time, `:38`). It accepts several `v1=` values and succeeds if any matches. The docs only show one, so that is our assumption about rotation.
- An empty secret or header fails closed (`:12-14`).
- Bad signature: `400`, outcome `invalid_signature`, nothing stored. Test: `test_bad_or_stale_signature_is_rejected` (`BillingFlowTest.php:235`; wrong secret, and a timestamp 600 s old).
- The CSRF exemption is in `bootstrap/app.php:20`. The route itself is `routes/web.php:36`, outside the `auth` group.

### Ping answered with 204

- A signed `ping` returns `204 No Content` and is logged with outcome `ping` (`PixelforgeWebhookController.php:45-50`). Nothing is stored in `webhook_events`.
- The docs do not show a `ping` body (see gaps), so the check only looks at `type === 'ping'`. It does not need `id` or `data`.
- Test: `test_ping_is_acknowledged_with_2xx_and_changes_nothing` (`BillingFlowTest.php:290`). It covers a full ping and a bare `{"type":"ping"}`.

### Fast `2xx`, no redirect

- Every success path returns `204` (`PixelforgeWebhookController.php:49, 75, 94`). The work done in the request is one insert into `webhook_events` and one short DB transaction in `GenerationSync::apply()`. There is no queue hop and no call to Pixelforge. (See the cold-cache caveat in section 2.)
- Redirects are avoided because the route is in `web.php` but outside the `auth` and `guest` middleware, so nothing issues a `302`. Bad input returns `400`, not a redirect.
- Test: `test_webhook_endpoint_never_redirects_even_for_a_signed_in_browser_or_bad_input` (`BillingFlowTest.php:308`) posts an empty body, `not json` and an event with no type while signed in, and asserts the status is never `3xx`.
- Non-`2xx` answers are deliberate: `400` for a bad signature or a malformed event. Pixelforge will retry those, which is harmless because we store nothing.

### Permanent vs temporary errors, and Retry-After

Classification lives in `app/Pixelforge/PixelforgeException.php`:

| Group | Codes | Where |
| --- | --- | --- |
| Permanent (never retried) | `invalid_api_key`, `validation_failed`, `content_policy_violation`, `daily_limit_exceeded`, `idempotency_key_reused`, `not_found` | `:32-39` |
| Temporary (retried) | `rate_limit_exceeded`, `concurrency_limit_exceeded`, `overloaded` | `:42` |
| Also retried | any `5xx`, and a `429` with no error code | `:45-54` |

- `Retry-After` is read only when numeric (`:21-27`). The docs say it is in seconds. An HTTP-date value would be ignored and the exponential backoff used instead.
- The job delay is `max(Retry-After, 2^min(attempt,7) capped at 120) + 0..3 s` of jitter (`SubmitGeneration.php:112-116`). It gives up after 12 attempts (`:27, 103`).
- A permanent error fails the generation with a user-facing message and releases the hold (`SubmitGeneration.php:81-96`, `GenerationSync::failLocally()` at `GenerationSync.php:110-126`).
- `daily_limit_exceeded` is a `429` but is treated as permanent: the docs say the quota is per UTC day, so waiting 12 minutes cannot help. The activity log also marks it `error`, not `retryable_error` (`PixelforgeClient.php:61`).
- Tests: `test_permanent_errors_are_not_retried_and_cost_nothing` (`BillingFlowTest.php:343`; one create call, nothing released to the queue, balance restored) and `test_temporary_errors_are_retried_after_retry_after` (`:372`; 429 x2, 503, 500 and a bare 502 are all released back to the queue and the generation stays `pending`).

### 409 `idempotency_key_reused`

- Treated as permanent and as a **local-only** failure (`Generation::LOCAL_ONLY_FAILURES`, `app/Models/Generation.php:34`): the hold is released and the user sees a message, but if Pixelforge later says the generation exists, Pixelforge wins (`GenerationSync.php:51-53, 68-72`).
- Why: our key is the row's UUID and the body never changes, so a 409 should not happen. If it does, it means Pixelforge already holds something under our key, and that thing can still be billed.
- It is logged at `error` level (`SubmitGeneration.php:92-94`).
- Test: `test_idempotency_conflict_is_not_final_if_pixelforge_reports_the_generation` (`BillingFlowTest.php:387`).

### The reconciler

- `generations:reconcile`, scheduled every minute without overlap (`routes/console.php:16`).
- Part 1: rows in `queued` or `processing` that have a `pixelforge_id` and have not changed for 60 s (`--older-than=60`) are polled with `GET /v1/generations/{id}` and passed through `GenerationSync::apply()`. At most 50 per run, to stay under 60 requests/minute (`app/Console/Commands/ReconcileGenerations.php:19, 27-46`). A retryable error stops the run so it does not burn the rate limit (`:39-41`).
- Part 2: rows still `pending` after 5 minutes get `SubmitGeneration` dispatched again (`:47-52`). This is safe only because of the idempotency key.
- Test: the two "accepted but not recorded" tests (`BillingFlowTest.php:170` and `:199`).

### A duplicate webhook is charged once

Three layers, each enough on its own:

1. `webhook_events.id` is the primary key (`database/migrations/2026_11_16_000004_create_webhook_events_table.php:12`). A second delivery of an already processed event raises a unique violation, which we turn into `204` plus a `duplicate` log row (`PixelforgeWebhookController.php:62-77`). A duplicate whose first copy did not finish (`processed_at` is null) is processed again rather than dropped (`:72`).
2. `GenerationSync::apply()` locks the generation row (`GenerationSync.php:39`). A final status is never overwritten (`:55-66`), so a different event for the same final state also changes nothing.
3. `credit_transactions` has a unique index on `(generation_id, type)` (`database/migrations/2026_11_16_000003_create_credit_transactions_table.php:21`), and `Ledger::settle()` checks for existing rows before posting (`app/Pixelforge/Ledger.php:46-63`).

Test: `test_success_charges_exact_actual_price_once_even_with_duplicate_webhooks` (`BillingFlowTest.php:124`) sends the same event id twice and a second event id for the same result, then asserts exactly one `charge` row and the exact balance. Out-of-order delivery (a late `processing` after `succeeded`) is `test_out_of_order_events_never_move_backwards` (`:141`).

### Pricing in picodollars

- 1 USD = 10^12 picodollars (`app/Support/Money.php:18`, `SCALE = 12`). Everything money-related is an integer in the database and in PHP. `brick/math` does the decimal work.
- Provider cost: `tokens x rate / 1M` per token type, summed as exact decimals, then rounded **up** to a whole picodollar only if a rate has more than 6 decimals (`app/Pixelforge/Pricing.php:71-89`). The `rates` used are the ones frozen on the generation, copied from the response (`GenerationSync.php:81`), not today's rates.
- User price: cost x (100 + markup) / 100, rounded up; markup is `PIXELFORGE_MARKUP_PERCENT` (25 by default) and is saved on each generation when it is created (`Pricing.php:91-97`). With the current rates (`5.00`, `10.00`, `40.00`, `12.50`) the result is exact, so nothing is rounded.
- Why picodollars: Pixelforge reports 9 decimals; a 25% markup can add two more (`cost x 125 / 100`), so 11 decimals are needed. 12 gives headroom. The docs say "floating-point will drift", and that is the only warning they give.
- Tests: `tests/Unit/PricingTest.php:16` (docs' worked maximum), `:29` (estimate max matches docs), `:38` (the docs' succeeded example, exact), `:53` (markup rounds up), `:65` (odd rates).
- The estimate shown to the user is a range because text tokens are only known within +-15% and reasoning tokens within a range per quality (`Pricing.php:113-142`). The hold is the maximum price (`app/Http/Controllers/GenerationController.php:83`).

## 2. Failure scenarios and what the system does

| Scenario | What happens | Where / test |
| --- | --- | --- |
| `429 rate_limit_exceeded`, `429 concurrency_limit_exceeded`, `503 overloaded` | Job is released with `max(Retry-After, backoff)` plus jitter, up to 12 attempts. User sees `pending`. Each attempt is a row in `pixelforge_activity`. | `SubmitGeneration.php:99-117`; `BillingFlowTest.php:372` |
| `5xx`, or a `429` with no code | Retried like the above. **Assumption**: the docs list only `503 overloaded` for server-side trouble. We treat other `5xx` as retryable because the idempotency key makes it safe. | `PixelforgeException.php:45-54`; `BillingFlowTest.php:367-368` |
| `400 content_policy_violation` | Fail at once, hold released, message "Blocked by the content policy". Never billed. | `SubmitGeneration.php:82`; `BillingFlowTest.php:264` |
| `422 validation_failed`, `401 invalid_api_key`, `429 daily_limit_exceeded` | Fail at once with a message. `invalid_api_key` is also logged at error level. | `SubmitGeneration.php:81-96`; `BillingFlowTest.php:343` |
| Connection error or timeout on create | We cannot know if Pixelforge got it. Retry with the same key. The activity row has outcome `connection_error`. Timeouts are 5 s connect, 20 s total. | `SubmitGeneration.php:62-66`; `PixelforgeClient.php:42-49, 85-86` |
| We give up after 12 attempts | Local-only failure `submission_failed`, hold released. If Pixelforge did create it, a webhook or poll still corrects the row and charges. | `SubmitGeneration.php:103-109`; `GenerationSync.php:51-72`; `BillingFlowTest.php:276` |
| Queue worker dies mid-job | `failed()` marks it a local-only failure. A lost job for a `pending` row is re-dispatched after 5 minutes. | `SubmitGeneration.php:119-125`; `ReconcileGenerations.php:47-52` |
| Webhook arrives before the POST response | Matched by `metadata.generation_uuid`; `pixelforge_id` is filled in from the event. | `BillingFlowTest.php:152` |
| Webhook out of order (`processing` after `succeeded`) | Status only moves forward, so the late event is ignored. | `GenerationSync.php:76-78`; `BillingFlowTest.php:141` |
| Duplicate webhook | `204`, logged as `duplicate`, no second charge. | `PixelforgeWebhookController.php:62-77`; `BillingFlowTest.php:124` |
| All 6 webhook attempts fail | The reconciler polls `queued` and `processing` rows after 60 s. | `ReconcileGenerations.php:27-46` |
| `generation.failed` | Free. Hold released. `completed_at` is set even if the event lacks it. A bogus later `succeeded` is ignored. | `BillingFlowTest.php:221, 322` |
| Image link older than 24 h | UI shows an "expired" message. We do not copy images. | `app/Models/Generation.php:65-79` (listed under "With more time" in the README) |
| Webhook for an event type that is not `generation.*` and not `ping` | Stored in `webhook_events`, no generation touched, `204`. | `PixelforgeWebhookController.php:59, 86-91` |
| Unknown generation in a webhook | Logged as `unknown generation`, still `204` so Pixelforge stops retrying. | `PixelforgeWebhookController.php:86-89` |

### Pixelforge accepted the request but we failed to record it

This is the dangerous one: money is owed to Pixelforge, but our row never got a `pixelforge_id` (a crash or a database error right after the `POST` returned, or a lost response). Two tests in `tests/Feature/Pixelforge/BillingFlowTest.php` cover it.

1. **`test_accepted_but_not_recorded_then_webhook_arrives_charges_once`** (`:170-192`). The row is still `pending` with no Pixelforge id. A `generation.succeeded` webhook arrives. It is matched by `metadata.generation_uuid`, the row becomes `succeeded`, `pixelforge_id` is filled in, the hold is released and the price is charged once. The test then moves the clock 6 minutes, runs the reconciler and runs the lost job by hand. It asserts that nothing is re-dispatched (the original submit is the only one pushed), that the job sees the row is no longer `pending` and sends no HTTP request (`Http::assertNothingSent()`), that the balance does not change and that there is still exactly one generation.
2. **`test_accepted_but_not_recorded_is_recovered_by_the_reconciler_without_a_duplicate`** (`:199-219`). Same loss, but no webhook yet. After 6 minutes the reconciler dispatches `SubmitGeneration` again (`Queue::assertPushed(..., 2)`). The job sends the same `Idempotency-Key` (asserted with `hasHeader('Idempotency-Key', $g->uuid)`), the fake Pixelforge replies with `Idempotent-Replayed: true`, and no second generation is created. The webhook then settles it, and a duplicate delivery of that event is ignored. Balance and generation count are asserted.

Limits of that coverage, stated plainly:

- The fake replay in test 2 returns HTTP `200`, while the docs say a replay returns `202` (`:208`). The client accepts any `2xx`, so the behaviour is the same, but the fixture is not a copy of the documented status.
- A row that was **failed locally** (`submission_failed` after 12 attempts) and has **no** `pixelforge_id` is not polled by the reconciler, which only looks at `queued` and `processing` rows that have a Pixelforge id (`ReconcileGenerations.php:27-30`). It can only be corrected by a webhook (`test_local_give_up_is_corrected_if_pixelforge_did_create_it`, `:276`). If every webhook attempt also failed, Pixelforge would have billed us for a generation we show as failed and free. The daily report check against `GET /v1/usage` would show a count and cost mismatch for that day (`app/Reports/SpendReport.php:172-190`), so it is detectable, but not automatically repaired.
- Pixelforge can accept a request while we have a `pending` row with a job that is still retrying and, at the same time, the reconciler re-dispatches it after 5 minutes. The two jobs can race. The idempotency key makes that safe on the Pixelforge side and `GenerationSync::apply()` locks the row on ours. I did not write a test for two jobs running at the same moment.

### Cold pricing cache in the webhook path (known risk)

`GenerationSync` needs `Pricing`, which is built from `PixelforgeClient::pricingTable()` (`app/Providers/AppServiceProvider.php:19-22`). That method fetches the public `/v1/pricing` with a 5 s timeout, cached 10 minutes, and falls back to the documented table on any error (`PixelforgeClient.php:129-143`). So the first webhook after a cache expiry can wait up to about 5 s for that call before the controller body even runs (the controller takes `GenerationSync` as an argument, `PixelforgeWebhookController.php:16`). That is still under Pixelforge's 10 s limit, but it breaks the "respond quickly" advice for that one request. It is not covered by a test.

Also note: settlement uses the `rates` stored on the generation, never this cached table. The table is only used for the user-facing estimate and the markup percent, so a stale table cannot change a charge.

## 3. Gaps, ambiguities and surprises

Only things I could show from the docs page, a public endpoint or the code.

1. **The docs are not machine-readable or crawlable.** `/docs` is a client-rendered page; there is no OpenAPI spec (`/v1/openapi.json`, `/v1/docs` return a JSON 404). `curl https://pixelforge.promptedit.dev/docs` shows about 70 kB of HTML whose only readable data is the JSON props. I had to read the compiled JS chunk to get the prose.
2. **The shape of a `ping` event is not documented.** The docs list `ping` as an event ("You pressed Send test event") and show a full body only for `generation.succeeded`. A ping has no generation in `data.object`. I do not know if it carries an `id`, an `object` or `data`. That is why the handler only reads `type` and the test sends one with and one without `id` (`BillingFlowTest.php:292-303`). **Unverified** against a real ping.
3. **Replay status code.** The docs say a replay returns `202` with `Idempotent-Replayed: true`. My own replay fixture used `200` (`BillingFlowTest.php:208`). Nothing in the docs confirms which one the live API sends, and the client accepts both.
4. **"Same parameters" is not defined.** The `409` rule depends on it, but the docs do not say whether key order, the `metadata` object or default values (`size`, `quality`) count. We always send the same body for a given key, so we never hit it, but another client could.
5. **HTTP-level `5xx` is undocumented.** The error table lists `503 overloaded` only. `internal_error` appears in the failed-generation body (docs example: "Something went wrong on our side while generating. You were not charged; please retry.") but not in the HTTP error table. Our "retry any `5xx`" rule is an assumption (`PixelforgeException.php:44-54`).
6. **Inconsistent 404 shape.** `GET /v1/docs` and `/v1/openapi.json` return the JSON error envelope, while `GET /v1` (and a path outside `/v1`) returns an HTML "Not Found" page (observed 2026-11-17). A client that always calls `->json('error.code')` on a failed response gets `null` for the HTML case. That is why `PixelforgeException` has a fallback message and a `null` code (`PixelforgeException.php:19-29`) and why a bare `502` with an HTML body is in the test data (`BillingFlowTest.php:368`).
7. **`daily_limit_exceeded` has no documented `Retry-After` or reset time**, only "UTC". We treat it as permanent for the generation, so the user must resubmit tomorrow.
8. **The `/usage` meaning of `generations` is not stated.** The docs say "what Pixelforge billed" and "only succeeded generations are billed", so I count succeeded generations on the completion day. The docs never say whether failed or in-flight ones appear in `generations`. **Unverified** against real data, and it is what the report's `matches` flag depends on (`SpendReport.php:172-190`).
9. **"Fresh" image URLs are not fresh.** The docs say you can fetch a new `image_url` from `GET /v1/generations/{id}` but it "expires at the same time". So the only way to keep an image beyond 24 h is to download it. We do not do that yet (README, "With more time").
10. **No published sender IPs or allow-list.** The signature is the only authentication of a webhook. The host sits behind Cloudflare (`server: cloudflare` on every response), so the IP we log for API calls (`PixelforgeClient.php:68`, curl's `primary_ip`) is a Cloudflare edge address, not the application server. It is useful as an audit field, not for identifying Pixelforge.
11. **Rates look like prices but are strings with two decimals** (`"5.00"`), and the pricing endpoint reports reasoning as `{min, max}` objects. The docs page's own props carry them as `[min, max]` arrays. Our `Pricing::defaultTable()` follows the public endpoint (`Pricing.php:51-55`). The docs prose is consistent; only the internal prop shape differs, so this is a note rather than a defect.
12. **Our concurrency cap does not match theirs.** Pixelforge allows 3 concurrent generations per account, and our per-user in-flight cap is 10 (`app/Http/Controllers/GenerationController.php:45`). With several users, rows legitimately wait behind `concurrency_limit_exceeded` (documented `Retry-After: 5`). We rely on 429 and retry rather than a local queue. The README lists a Redis funnel as the better design.
13. **Retries count towards the 60/min limit** (docs). With the reconciler polling up to 50 rows a minute plus submit retries, a busy minute can hit `rate_limit_exceeded`. The reconciler stops the run on a retryable error (`ReconcileGenerations.php:39-41`).

## 4. What we log per Pixelforge call

Every API call we make and every webhook delivery we receive writes one row to `pixelforge_activity` (`database/migrations/2026_11_17_000001_create_pixelforge_activity_table.php`, model `app/Models/PixelforgeActivity.php`). Writing the log never breaks billing: a failed write is reported to the error handler and swallowed (`PixelforgeActivity.php:30-38`).

| Column | API call (`channel = api`) | Webhook (`channel = webhook`) |
| --- | --- | --- |
| `generation_id` | local generation the call belongs to (null if unknown) | local generation found by `metadata.generation_uuid` |
| `action` | `POST /generations`, `GET /generations/gen_...` | event type (`generation.succeeded`, `ping`, or the `Pixelforge-Event-Type` header) |
| `status_code` | HTTP status from Pixelforge | status we answered with |
| `outcome` | `ok`, `replayed`, `retryable_error`, `error`, `connection_error` (`PixelforgeClient.php:58-63`) | `processed`, `duplicate`, `ping`, `invalid_signature`, `malformed` |
| `error_code` | Pixelforge `error.code` | not set |
| `duration_ms` | wall time of the request (`PixelforgeClient.php:37-38`) | time to answer (`PixelforgeWebhookController.php:29`) |
| `attempt` | our submit attempt number (`submit_attempts`) | the `Pixelforge-Delivery-Attempt` header |
| `event_id` | not set | `evt_...` from the body, else the `Pixelforge-Event-Id` header |
| `ip` | the remote server IP curl connected to (`primary_ip`), empty for faked responses | `$request->ip()`, i.e. the sender as seen behind our trusted proxies |
| `detail` | `Retry-After`, `X-RateLimit-Remaining`, the `status` from the body (`PixelforgeClient.php:69-73`) | for example `status queued -> succeeded`, `delivery #2 of this event, ignored`, `unknown generation` |
| `created_at` | millisecond timestamp | millisecond timestamp |

Where you can see it:

- The admin generation page `/admin/generations/{id}` (an Inertia page).
- The JSON API `GET /api/admin/generations/{id}` and the MCP tool `get-generation-timeline` (see `docs/admin-api.md`). Both return stage timings and a `summary` with `api_calls`, `webhooks`, `duplicates_ignored` and `sender_ips` (`app/Reports/GenerationTimeline.php:55-60`).
- Test: `test_every_call_and_webhook_is_logged_and_shown_on_the_timeline_web_mcp_and_api` (`BillingFlowTest.php:419-457`). It checks the outcomes in order (`retryable_error`, `ok`, `processed`, `duplicate`), the error code and attempt numbers, the sender IP and delivery attempt on a webhook, and the duplicate count.

Not logged, to be honest about it:

- `GET /v1/usage` (`PixelforgeClient.php:117-126`) does not go through `send()`, so report calls have no activity row.
- `GET /v1/pricing` is not logged either (it is public and cached).
- The request and response bodies are not stored, except the webhook payload, which is kept in `webhook_events.payload`.
