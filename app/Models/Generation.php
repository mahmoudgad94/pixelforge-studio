<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    public const PENDING = 'pending';       // saved locally, not yet accepted by Pixelforge

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Order of the lifecycle. A generation only ever moves forward. */
    public const RANK = [
        self::PENDING => 0,
        self::QUEUED => 1,
        self::PROCESSING => 2,
        self::SUCCEEDED => 3,
        self::FAILED => 3,
    ];

    /**
     * Failures we recorded ourselves without Pixelforge confirming them. Pixelforge may
     * still have created the generation, so its later word (webhook or poll) wins.
     */
    public const LOCAL_ONLY_FAILURES = ['submission_failed', 'idempotency_key_reused'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    public function toFrontend(): array
    {
        $expired = $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_price' => Money::format($this->estimated_price_picos),
            'estimated_range' => [Money::format($this->estimated_min_price_picos), Money::format($this->estimated_max_price_picos)],
            'price' => Money::format($this->price_picos),
            'price_exact' => $this->price_picos === null ? null : Money::toUsdString($this->price_picos),
            'usage' => $this->usage,
            'image_url' => $expired ? null : $this->image_url,
            'image_expired' => $expired,
            'error' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_ms' => $this->completed_at && $this->created_at ? (int) $this->created_at->diffInMilliseconds($this->completed_at) : null,
            'can_download' => $this->status === self::SUCCEEDED && $this->image_url !== null && ! $expired,
        ];
    }
}
