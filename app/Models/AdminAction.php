<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAction extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Mark requests that waited past their 24 hours as expired (so lists never show a stale "pending"). */
    public static function expireOverdue(): void
    {
        static::query()->where('status', self::PENDING)->where('expires_at', '<', now())->update(['status' => self::EXPIRED]);
    }

    /** Public shape, same on the Approvals page and in the JSON API. Needs `requester` and `decider` loaded. */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'email' => $this->payload['email'] ?? null,
            'amount' => isset($this->payload['amount_picos']) ? Money::format((int) $this->payload['amount_picos']) : null,
            'reason' => $this->reason,
            'status' => $this->status,
            'client' => $this->client,
            'requested_by' => $this->requester?->name,
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
