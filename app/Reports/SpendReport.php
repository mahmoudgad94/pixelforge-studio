<?php

namespace App\Reports;

use App\Models\Generation;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spend, provider cost and profit per UTC day and per user, with each day
 * checked against Pixelforge GET /v1/usage. Days are the UTC day the
 * generation completed, which is how Pixelforge bills.
 */
class SpendReport
{
    public const DAY_PAGE_SIZE = 25;

    public const USER_PAGE_SIZE = 25;

    public function __construct(private UsageCache $usageCache) {}

    /** Token kinds Pixelforge reports per day; each must match ours, not only the cost. */
    private const TOKENS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /** The normalised UTC range: garbage falls back to the last 14 days, swapped dates are fixed, 92 days at most. */
    public function range(?string $start, ?string $end): array
    {
        $end = $this->day($end) ?? now()->utc()->startOfDay();
        $start = $this->day($start) ?? $end->copy()->subDays(13);
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > 91) {
            $start = $end->copy()->subDays(91);
        }

        return [$start, $end];
    }

    /**
     * Totals come from SQL (SUM/COUNT grouped by UTC day), so memory and query count stay flat as
     * generations grow. $userPage null returns every per-day-and-user row (API, MCP); a number returns
     * that page and adds perUserPage meta. $refreshUsage bypasses the usage cache (behind a short lock).
     */
    public function build(?string $start, ?string $end, ?int $userPage = null, bool $refreshUsage = false): array
    {
        [$start, $end] = $this->range($start, $end);

        $dayExpr = 'DATE(generations.completed_at)';
        $base = fn () => DB::table('generations')
            ->where('generations.status', Generation::SUCCEEDED)
            ->whereBetween('generations.completed_at', [$start, $end->copy()->endOfDay()]);
        $sums = 'COUNT(*) as generations, COALESCE(SUM(generations.price_picos), 0) as spend, COALESCE(SUM(generations.provider_cost_picos), 0) as cost';

        $tokenSelects = collect(self::TOKENS)->map(fn ($k) => $this->tokenSum($k).' as '.$k)->implode(', ');
        $byDay = $base()
            ->selectRaw("$dayExpr as day, $sums, $tokenSelects")
            ->groupBy(DB::raw($dayExpr))
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        $userQuery = $base()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw("$dayExpr as day, generations.user_id, users.name, users.email, $sums")
            ->groupBy(DB::raw($dayExpr), 'generations.user_id', 'users.name', 'users.email')
            ->orderByDesc('day')
            ->orderBy('generations.user_id');
        $perUserPage = null;
        if ($userPage === null) {
            $userRows = $userQuery->get();
        } else {
            $paged = $userQuery->paginate(self::USER_PAGE_SIZE, ['*'], 'users_page', max(1, $userPage));
            $userRows = collect($paged->items());
            $perUserPage = ['page' => $paged->currentPage(), 'last_page' => $paged->lastPage(), 'per_page' => $paged->perPage(), 'total' => $paged->total()];
        }

        $usageResult = $this->usageCache->get($start, $end, $refreshUsage);
        $usage = $usageResult['data'] === null ? null : collect($usageResult['data']);

        $days = [];
        for ($d = $end->copy(); $d->gte($start); $d->subDay()) {
            $date = $d->toDateString();
            $row = $byDay->get($date);
            $ours = [
                'generations' => (int) ($row->generations ?? 0),
                'spend' => (int) ($row->spend ?? 0),
                'cost' => (int) ($row->cost ?? 0),
                'tokens' => collect(self::TOKENS)->mapWithKeys(fn ($k) => [$k => (int) ($row->$k ?? 0)])->all(),
            ];
            $theirs = $usage?->get($date);
            $days[] = [
                'date' => $date,
                'generations' => $ours['generations'],
                'spend' => Money::format($ours['spend']),
                'cost' => Money::toUsdString($ours['cost'], 9),
                'profit' => Money::format($ours['spend'] - $ours['cost']),
                'pixelforge_generations' => $theirs['generations'] ?? null,
                'pixelforge_cost' => $theirs['cost_usd'] ?? null,
                'matches' => $this->matches($usage !== null, $ours, $theirs),
            ];
        }

        $totals = ['generations' => 0, 'spend' => 0, 'cost' => 0];
        foreach ($byDay as $row) {
            foreach (array_keys($totals) as $k) {
                $totals[$k] += (int) $row->$k;
            }
        }

        $result = [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'perUser' => $userRows->map(fn ($r) => [
                'date' => (string) $r->day,
                'user' => $r->name.' <'.$r->email.'>',
                'generations' => (int) $r->generations,
                'spend' => Money::format((int) $r->spend),
                'cost' => Money::toUsdString((int) $r->cost, 9),
                'profit' => Money::format((int) $r->spend - (int) $r->cost),
            ])->all(),
            'totals' => [
                'generations' => $totals['generations'],
                'spend' => Money::format($totals['spend']),
                'cost' => Money::toUsdString($totals['cost'], 9),
                'profit' => Money::format($totals['spend'] - $totals['cost']),
            ],
            'usageError' => $usageResult['error'],
            'usageCheckedAt' => $usageResult['checked_at'],
            'refreshThrottled' => $usageResult['refresh_throttled'],
        ];
        if ($perUserPage !== null) {
            $result['perUserPage'] = $perUserPage;
        }

        return $result;
    }

    /**
     * Everything that finished on one UTC day, newest first: billed (succeeded) generations
     * with their exact numbers, and failed ones, which cost nothing. At most 500 (API and MCP);
     * the admin page pages through dayPage() instead.
     */
    public function dayDetail(string $date): ?array
    {
        $day = $this->day($date);
        if (! $day) {
            return null;
        }

        return $this->dayQuery($day)->limit(500)->get()->map(fn (Generation $g) => $this->present($g))->all();
    }

    /** One page (25) of a day's generations, plus the meta the "Load more" button needs. */
    public function dayPage(string $date, int $page = 1): ?array
    {
        $day = $this->day($date);
        if (! $day) {
            return null;
        }
        $paged = $this->dayQuery($day)->paginate(self::DAY_PAGE_SIZE, ['*'], 'page', max(1, $page));

        return [
            'generations' => collect($paged->items())->map(fn (Generation $g) => $this->present($g))->all(),
            'meta' => ['page' => $paged->currentPage(), 'last_page' => $paged->lastPage(), 'per_page' => $paged->perPage(), 'total' => $paged->total()],
        ];
    }

    private function dayQuery(Carbon $day)
    {
        return Generation::query()
            ->with('user:id,name,email')
            ->whereIn('status', [Generation::SUCCEEDED, Generation::FAILED])
            ->whereBetween('completed_at', [$day, $day->copy()->endOfDay()])
            ->orderByDesc('completed_at')
            ->orderByDesc('id');
    }

    private function present(Generation $g): array
    {
        $billed = $g->status === Generation::SUCCEEDED;
        $expired = $g->image_url_expires_at !== null && $g->image_url_expires_at->isPast();

        return [
            'id' => $g->id,
            'pixelforge_id' => $g->pixelforge_id,
            'completed_at' => $g->completed_at->copy()->utc()->format('H:i:s'),
            'user' => $g->user?->name,
            'email' => $g->user?->email,
            'status' => $g->status,
            'prompt' => $g->prompt,
            'size' => $g->size,
            'quality' => $g->quality,
            'references' => count($g->reference_images ?? []),
            'tokens' => $billed ? array_intersect_key($g->usage ?? [], array_flip(self::TOKENS)) : null,
            'estimate' => Money::format($g->estimated_price_picos),
            'cost' => $billed ? Money::toUsdString($g->provider_cost_picos, 9) : '0.000000000',
            'spend' => $billed ? Money::format($g->price_picos) : '$0.00',
            'profit' => $billed ? Money::format($g->price_picos - $g->provider_cost_picos) : '$0.00',
            'error' => $billed ? null : $g->error_message,
            'image_url' => $billed && ! $expired ? $g->image_url : null,
        ];
    }

    /**
     * SUM of one token kind inside the usage JSON, in SQL. Driver-aware because JSON access differs;
     * the expressions are short enough that this beats summing in PHP, which would load every row's JSON.
     */
    private function tokenSum(string $kind): string
    {
        $column = DB::getQueryGrammar()->wrap('generations.usage');
        $expr = match (DB::connection()->getDriverName()) {
            'pgsql' => "(($column)::jsonb ->> '$kind')::bigint",
            'mysql', 'mariadb' => "CAST(JSON_UNQUOTE(JSON_EXTRACT($column, '$.$kind')) AS SIGNED)",
            default => "CAST(json_extract($column, '$.$kind') AS INTEGER)",
        };

        return "COALESCE(SUM($expr), 0)";
    }

    /** A strict Y-m-d date in UTC, or null for anything else (garbage falls back to the default range). */
    private function day(?string $value): ?Carbon
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = Carbon::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * True when Pixelforge billed exactly what we recorded: same count, same exact cost, same tokens.
     * Null when usage could not be loaded. Pixelforge lists every day (zeros included), so a missing
     * row for a day we billed is a mismatch.
     */
    private function matches(bool $usageLoaded, array $ours, ?array $theirs): ?bool
    {
        if (! $usageLoaded) {
            return null;
        }
        if ($theirs === null) {
            return $ours['generations'] === 0 ? null : false;
        }
        if ((int) $theirs['generations'] !== $ours['generations'] || Money::fromUsd($theirs['cost_usd']) !== $ours['cost']) {
            return false;
        }
        foreach (self::TOKENS as $kind) {
            if (isset($theirs[$kind]) && (int) $theirs[$kind] !== $ours['tokens'][$kind]) {
                return false;
            }
        }

        return true;
    }
}
