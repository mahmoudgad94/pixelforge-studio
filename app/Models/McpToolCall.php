<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One call to the admin MCP server (or a refused request). Written by App\Mcp\McpCallLog only. */
class McpToolCall extends Model
{
    use Prunable;

    public const OK = 'ok';

    public const ERROR = 'error';

    public const REFUSED = 'refused';

    public const OUTCOMES = [self::OK, self::ERROR, self::REFUSED];

    public const UPDATED_AT = null;

    /** Milliseconds in created_at, so calls in the same second keep their order. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['duration_ms' => 'integer', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rows older than the retention window are deleted by `model:prune` (scheduled daily). */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(90));
    }

    /** Optional filters shared by the Approvals page and the JSON API. */
    public function scopeFilter(Builder $query, ?string $tool, ?string $outcome): Builder
    {
        return $query
            ->when($tool, fn (Builder $q) => $q->where('tool', $tool))
            ->when(in_array($outcome, self::OUTCOMES, true), fn (Builder $q) => $q->where('outcome', $outcome));
    }

    /** Public shape, same on the page and in the API. */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'client' => $this->client,
            'user' => $this->user?->name,
            'user_email' => $this->user?->email,
            'tool' => $this->tool,
            'arguments' => $this->arguments_summary,
            'outcome' => $this->outcome,
            'error' => $this->error_message,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
