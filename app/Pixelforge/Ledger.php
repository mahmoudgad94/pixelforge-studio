<?php

namespace App\Pixelforge;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Credit ledger. Every balance change is a row in credit_transactions, and the
 * unique (generation_id, type) index means a generation can be held, released
 * and charged at most once each, even if two webhooks race each other.
 *
 * Callers must already be inside a DB transaction.
 */
class Ledger
{
    public function purchase(User $user, int $amountPicos): void
    {
        DB::transaction(function () use ($user, $amountPicos) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->post($locked, null, CreditTransaction::PURCHASE, $amountPicos);
        });
    }

    /** Reserve the worst-case price while the generation runs. Returns false if the user can't afford it. */
    public function hold(User $lockedUser, Generation $generation): bool
    {
        if ($lockedUser->balance_picos < $generation->hold_picos) {
            return false;
        }

        $this->post($lockedUser, $generation, CreditTransaction::HOLD, -$generation->hold_picos);

        return true;
    }

    /** Release the hold (if any) and, for a success, charge the real price. Idempotent. */
    public function settle(Generation $lockedGeneration): void
    {
        $user = User::query()->lockForUpdate()->findOrFail($lockedGeneration->user_id);

        $existing = CreditTransaction::query()
            ->where('generation_id', $lockedGeneration->id)
            ->pluck('amount_picos', 'type');

        if ($existing->has(CreditTransaction::HOLD) && ! $existing->has(CreditTransaction::HOLD_RELEASE)) {
            $this->post($user, $lockedGeneration, CreditTransaction::HOLD_RELEASE, -$existing[CreditTransaction::HOLD]);
        }

        if ($lockedGeneration->status === Generation::SUCCEEDED
            && $lockedGeneration->price_picos !== null
            && ! $existing->has(CreditTransaction::CHARGE)) {
            $this->post($user, $lockedGeneration, CreditTransaction::CHARGE, -$lockedGeneration->price_picos);
        }
    }

    private function post(User $lockedUser, ?Generation $generation, string $type, int $amount): void
    {
        $lockedUser->balance_picos += $amount;
        $lockedUser->save();

        CreditTransaction::create([
            'user_id' => $lockedUser->id,
            'generation_id' => $generation?->id,
            'type' => $type,
            'amount_picos' => $amount,
            'balance_after_picos' => $lockedUser->balance_picos,
        ]);
    }
}
