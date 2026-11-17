# Admin API and MCP server

Two machine-readable ways into the same admin data: a small JSON API and an MCP server. Both are for admins (`users.is_admin = true`) only, both are throttled to 60 requests/minute, and both accept the same bearer tokens.

Everything below comes from the code and the tests. Example responses use made-up values with the shape taken from the code. Money is a string: dollar fields are formatted like `"$0.070700"` (`App\Support\Money::format`, six decimals) and exact fields are plain decimals such as `"0.056560000"` (nine decimals, `Money::toUsdString`).

## How to get a token

There are three ways. All end up as `Authorization: Bearer <token>`.

### 1. Static token from the command line

```bash
php artisan mcp:token you@example.com
```

Defined in `routes/console.php:18`. The user must exist and be an admin. It prints a token that starts with `pfs_`, shown once. Running it again replaces the previous token. Only the SHA-256 hash is stored (`users.mcp_token_hash`).

### 2. Static token from the admin UI

Signed in as an admin, use **Admin > Approvals** (`/admin/approvals`). The page creates a token with `POST /admin/mcp-token` (`app/Http/Controllers/Admin/ApprovalController.php:47-53`, throttled 10/minute) and shows it once. `DELETE /admin/mcp-token` revokes it. Same format and same one-token-per-admin rule as above.

### 3. OAuth (Laravel Passport)

This is what claude.ai, Claude Desktop and Claude Code use.

- Discovery and dynamic client registration come from `Mcp::oauthRoutes()` (`routes/ai.php:9`): `/.well-known/oauth-protected-resource/mcp/admin`, `/.well-known/oauth-authorization-server` and `POST /oauth/register`. Only Claude's callbacks and local callbacks may register (`AdminMcpOAuthTest::test_admin_connects_with_the_full_oauth_flow`).
- Flow: authorization code with PKCE (S256) against `/oauth/authorize` and `/oauth/token`. The consent screen is only shown to admins; a non-admin gets `403` (`app/Providers/AppServiceProvider.php:46-52`).
- Access tokens last 1 day and refresh tokens 30 days (`AppServiceProvider.php:42-43`).
- The tests request the scope `mcp:use`. The middleware does not check scopes, only that the token belongs to an admin (`app/Http/Middleware/AuthenticateMcpToken.php`).
- OAuth needs signing keys: `php artisan passport:keys` locally, or `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` in production (see the README).

### How a request is authenticated

`AuthenticateMcpToken` (`app/Http/Middleware/AuthenticateMcpToken.php:19-41`):

1. No bearer token: `401 {"error":"Unauthorized"}`. On the MCP route `laravel/mcp` adds a `WWW-Authenticate: Bearer realm="mcp", resource_metadata="..."` header so clients can find OAuth (`AdminMcpOAuthTest::test_unauthenticated_request_points_to_the_metadata`).
2. The SHA-256 of the token is matched against an admin's `mcp_token_hash`. If that fails, the Passport `api` guard is tried (`config/auth.php:46-48`). No match: `401`.
3. A Passport user who is not an admin: `403 {"error":"Forbidden: only Pixelforge admins can use this MCP server."}`. A static token of a non-admin is simply not matched, so it gets `401` (`AdminMcpTest::test_endpoint_requires_an_admin_token`).

## JSON API

Routes: `routes/api.php`. Laravel adds the `/api` prefix, so the full paths are under `/api/admin`. Middleware: `AuthenticateMcpToken`, `throttle:60,1`. Controller: `app/Http/Controllers/Api/AdminApiController.php`.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/admin/report` | Spend, provider cost and profit per UTC day and per user, checked against Pixelforge `GET /v1/usage`. |
| GET | `/api/admin/report/days/{date}` | Every generation that finished on one UTC day. |
| GET | `/api/admin/generations/{id}` | Timeline of one generation: stages, every Pixelforge call and webhook. |

Send `Accept: application/json`. For a bad parameter the controller uses Laravel's normal validation; I did not test what an API client without that header receives. **Unverified**.

### `GET /api/admin/report`

Query parameters (both optional, `Y-m-d`, UTC; `AdminApiController.php:20-23`):

| Name | Default | Notes |
| --- | --- | --- |
| `start_date` | 13 days before `end_date` | Swapped with `end_date` if later. |
| `end_date` | today (UTC) | |

Ranges longer than 92 days are cut to 92 days ending at `end_date` (`app/Reports/SpendReport.php:23-33`). A value that fails the controller's `date_format:Y-m-d` rule is a validation error (`422` for a JSON client). Inside `SpendReport` itself, garbage falls back to the default range (`tests/Feature/Reports/SpendReportTest.php:45`).

Days are the UTC day a generation **completed**, which is how Pixelforge bills, so `cost` per day lines up with `GET /v1/usage`.

Example (shape from `SpendReport::build`, `SpendReport.php:91-108`; values illustrative):

```json
{
  "start": "2026-11-04",
  "end": "2026-11-17",
  "days": [
    {
      "date": "2026-11-17",
      "generations": 1,
      "spend": "$0.070700",
      "cost": "0.056560000",
      "profit": "$0.014140",
      "pixelforge_generations": 1,
      "pixelforge_cost": "0.056560000",
      "matches": true
    }
  ],
  "perUser": [
    {
      "date": "2026-11-17",
      "user": "Ana <ana@example.com>",
      "generations": 1,
      "spend": "$0.070700",
      "cost": "0.056560000",
      "profit": "$0.014140"
    }
  ],
  "totals": { "generations": 1, "spend": "$0.070700", "cost": "0.056560000", "profit": "$0.014140" },
  "usageError": null
}
```

- `days` is newest first and includes days with no activity.
- `matches` is `true` only when Pixelforge's count, exact cost and per-kind token counts all equal ours, `false` on any difference, and `null` when usage could not be loaded or there is nothing to compare for that day (`SpendReport.php:172-190`).
- `usageError` carries the message if the `GET /v1/usage` call failed. The report still returns our numbers.
- Test: `BillingFlowTest::test_every_call_and_webhook_is_logged_and_shown_on_the_timeline_web_mcp_and_api` asserts `totals.generations` equals `1`.

### `GET /api/admin/report/days/{date}`

`{date}` must match `\d{4}-\d{2}-\d{2}` (`routes/api.php:11`) and be a real calendar date, otherwise `404` (`AdminApiController.php:31-32`; the test uses `2026-02-31`).

Response (`AdminApiController.php:34`, rows from `SpendReport::dayDetail`, `SpendReport.php:115-153`). Succeeded and failed generations that completed that day, newest first, at most 500:

```json
{
  "date": "2026-11-17",
  "generations": [
    {
      "id": 42,
      "pixelforge_id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K",
      "completed_at": "14:03:29",
      "user": "Ana",
      "email": "ana@example.com",
      "status": "succeeded",
      "prompt": "a red fox",
      "size": "1024x1024",
      "quality": "medium",
      "references": 0,
      "tokens": { "input_text_tokens": 12, "input_image_tokens": 0, "output_tokens": 1100, "reasoning_tokens": 1000 },
      "estimate": "$0.070000",
      "cost": "0.056560000",
      "spend": "$0.070700",
      "profit": "$0.014140",
      "error": null,
      "image_url": "https://pixelforge.promptedit.dev/images/..."
    }
  ]
}
```

For a failed generation `tokens` is `null`, `cost` is `"0.000000000"`, `spend` and `profit` are `"$0.00"`, and `error` holds the message. `image_url` is `null` for failed rows and once the 24 h link has expired.

### `GET /api/admin/generations/{id}`

`{id}` is either our numeric generation id (`"42"`) or the Pixelforge id (`"gen_..."`) (`AdminApiController.php:39`). Unknown id: `404`.

Response is `GenerationTimeline::build` (`app/Reports/GenerationTimeline.php:16-63`):

```json
{
  "generation": {
    "id": 42,
    "pixelforge_id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K",
    "user": "ana@example.com",
    "prompt": "a red fox",
    "size": "1024x1024",
    "quality": "medium",
    "status": "succeeded",
    "error": null,
    "usage": { "input_text_tokens": 12, "input_image_tokens": 0, "output_tokens": 1100, "reasoning_tokens": 1000, "total_tokens": 2112 },
    "estimate": "$0.070000",
    "estimate_range": ["$0.050000", "$0.090000"],
    "cost": "0.056560000",
    "price": "$0.070700",
    "profit": "$0.014140",
    "submit_attempts": 2,
    "created_at": "2026-11-17T14:03:11+00:00",
    "submitted_at": "2026-11-17T14:03:12+00:00",
    "started_at": "2026-11-17T14:03:13+00:00",
    "completed_at": "2026-11-17T14:03:29+00:00"
  },
  "stages": [
    { "label": "Waiting in our queue, then accepted by Pixelforge", "ms": 1000 },
    { "label": "Queued at Pixelforge until processing started", "ms": 1000 },
    { "label": "Processing until finished", "ms": 16000 },
    { "label": "Total, click to result", "ms": 18000 }
  ],
  "summary": { "api_calls": 2, "webhooks": 2, "duplicates_ignored": 1, "sender_ips": ["203.0.113.7"] },
  "activity": [
    {
      "id": 1,
      "at": "2026-11-17T14:03:11.250Z",
      "channel": "api",
      "action": "POST /generations",
      "status_code": 429,
      "outcome": "retryable_error",
      "error_code": "concurrency_limit_exceeded",
      "duration_ms": 180,
      "attempt": 1,
      "event_id": null,
      "ip": null,
      "detail": "Retry-After 5, rate limit left 58"
    }
  ]
}
```

- A stage `ms` is `null` when one of its two timestamps is missing.
- `cost`, `price` and `profit` are zero for anything that did not succeed.
- `activity` is every `pixelforge_activity` row for the generation, oldest first. The columns are explained in `docs/pixelforge-integration-notes.md`, section 4.
- Test: the same `BillingFlowTest` test checks `summary.webhooks` equals `2` and that the call without a token returns `401`.

## MCP server

- Class: `app/Mcp/AdminServer.php`. Name "Pixelforge Studio Admin", version `1.0.0`.
- Route: `POST /mcp/admin` (also registered for `GET` and `DELETE` by `laravel/mcp`). Defined in `routes/ai.php:13` with `AuthenticateMcpToken` and `throttle:60,1`.
- Transport: MCP over HTTP (JSON-RPC). The tests call `tools/list` directly:

```bash
curl -sS https://<host>/mcp/admin \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

  Tool calls use the standard MCP `tools/call` method. The repository's tests call tools through `AdminServer::actingAs($admin)->tool(...)` rather than over HTTP, so I have not exercised `tools/call` over the wire myself.
- Server instructions given to the model: money is USD with up to 9 decimals, days are UTC completion days, and write tools never change data directly (they create a request an admin must approve).
- Connect clients as described in the README ("Connect Claude to the admin MCP").

### Tools

Tool names are generated by `laravel/mcp` from the class names. The README lists four verbatim (`get-spend-report`, `find-generations`, `get-user-credits`, `get-action-status`) and the test asserts `request-credit-adjustment`. I assume the other two are `get-day-generations` and `get-generation-timeline` by the same rule; `tools/list` is authoritative. Results are structured content (`Response::structured`).

| Tool (class) | Kind | Arguments | Returns |
| --- | --- | --- | --- |
| `get-spend-report` (`GetSpendReport`) | read-only | `start_date`, `end_date` (optional, `Y-m-d`) | Same as `GET /api/admin/report`, except `days` only lists days with activity (ours or Pixelforge's) to keep it short (`GetSpendReport.php:25-26`). |
| `get-day-generations` (`GetDayGenerations`) | read-only | `date` (required, `Y-m-d`) | `{ "date", "count", "generations": [...] }`, same rows as the day endpoint above. |
| `find-generations` (`FindGenerations`) | read-only | `email`, `status` (`pending`, `queued`, `processing`, `succeeded`, `failed`), `limit` (1 to 50, default 20) | `{ "generations": [ { "id", "pixelforge_id", "user", "status", "prompt", "size", "quality", "estimated_price", "price_exact", "error", "created_at", "completed_at" } ] }`, newest first. |
| `get-generation-timeline` (`GetGenerationTimeline`) | read (no `IsReadOnly` annotation in the code) | `id`: our number (`"42"`) or the Pixelforge id (`"gen_..."`) | Same as `GET /api/admin/generations/{id}`. Unknown id returns a tool error "No generation {id}." |
| `get-user-credits` (`GetUserCredits`) | read-only | `email` (required) | `{ "email", "balance": "$3.00", "balance_exact": "3.000000000000", "ledger": [ { "type", "generation_id", "amount", "balance_after", "at" } ] }`, latest 15 ledger rows. Tool error if the email is unknown. |
| `request-credit-adjustment` (`RequestCreditAdjustment`) | write, approval-gated | `email`, `amount_usd` (string like `"2.50"` or `"-1"`, at most 9 decimals, up to 4 integer digits), `reason` (5 to 500 chars), all required | `{ "request_id", "status": "pending", "amount", "email", "approve_at", "expires_at", "note" }`. The balance is **not** changed. |
| `get-action-status` (`GetActionStatus`) | read-only | `request_id` (required, integer) | `{ "request_id", "status", "decided_by", "decided_at" }` where `status` is `pending`, `approved`, `rejected` or `expired`. |

### The write tool: no direct changes

`request-credit-adjustment` only creates an `AdminAction` row with `status = pending` and `expires_at = now + 1 day` (`app/Pixelforge/AdminActions.php:17-27`). A human admin then approves or rejects it at **Admin > Approvals** (`/admin/approvals`). Rules enforced when approving (`AdminActions.php:29-64`):

- A pending request that is past its expiry is marked `expired` and cannot be approved.
- Approval takes a row lock and checks the status, so a request cannot be applied twice.
- An adjustment that would make the balance negative is refused.
- An applied change is a `credit_transactions` row of type `adjustment`.

Tests: `tests/Feature/Pixelforge/AdminMcpTest.php` (write tool creates a request only; approve once; rejected and expired change nothing) and `tests/Feature/Pixelforge/AdminMcpOAuthTest.php` (OAuth discovery, full flow, non-admin refused, static token still works).
