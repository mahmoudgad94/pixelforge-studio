<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class PixelforgeActivity extends Model
{
    protected $table = 'pixelforge_activity';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /** Logging must never break billing: a failed write is reported and ignored. */
    public static function record(array $attributes): void
    {
        try {
            static::create($attributes);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function toFrontend(): array
    {
        return [
            'id' => $this->id,
            'at' => $this->created_at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'channel' => $this->channel,
            'action' => $this->action,
            'status_code' => $this->status_code,
            'outcome' => $this->outcome,
            'error_code' => $this->error_code,
            'duration_ms' => $this->duration_ms,
            'attempt' => $this->attempt,
            'event_id' => $this->event_id,
            'ip' => $this->ip,
            'detail' => $this->detail,
        ];
    }
}
