<?php

namespace App\Pixelforge;

use App\Models\AdminAction;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Two-step writes: an agent can only *request* a change; a human admin approves it.
 */
class AdminActions
{
    public function requestCreditAdjustment(User $requester, User $target, int $amountPicos, string $reason, ?string $client = null): AdminAction
    {
        return AdminAction::create([
            'type' => 'credit_adjustment',
            'payload' => ['user_id' => $target->id, 'email' => $target->email, 'amount_picos' => (string) $amountPicos],
            'reason' => $reason,
            'status' => AdminAction::PENDING,
            'requested_by' => $requester->id,
            'client' => $client,
            'expires_at' => now()->addDay(),
        ]);
    }

    public function approve(AdminAction $action, User $admin): AdminAction
    {
        // Expire first, outside the transaction, so the status change isn't rolled back.
        if (AdminAction::query()->whereKey($action->id)->where('status', AdminAction::PENDING)
            ->where('expires_at', '<', now())->update(['status' => AdminAction::EXPIRED])) {
            throw new RuntimeException("Action #{$action->id} expired.");
        }

        return DB::transaction(function () use ($action, $admin) {
            $locked = AdminAction::query()->lockForUpdate()->findOrFail($action->id);

            if ($locked->status !== AdminAction::PENDING) {
                throw new RuntimeException("Action #{$locked->id} is already {$locked->status}.");
            }

            if ($locked->type === 'credit_adjustment') {
                $user = User::query()->lockForUpdate()->findOrFail($locked->payload['user_id']);
                $amount = (int) $locked->payload['amount_picos'];
                if ($user->balance_picos + $amount < 0) {
                    throw new RuntimeException('Adjustment would make the balance negative ('.Money::format($user->balance_picos + $amount).').');
                }
                $user->balance_picos += $amount;
                $user->save();
                CreditTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'adjustment',
                    'amount_picos' => $amount,
                    'balance_after_picos' => $user->balance_picos,
                ]);
            }

            $locked->update(['status' => AdminAction::APPROVED, 'decided_by' => $admin->id, 'decided_at' => now()]);

            return $locked;
        });
    }

    public function reject(AdminAction $action, User $admin): AdminAction
    {
        $updated = AdminAction::query()->whereKey($action->id)->where('status', AdminAction::PENDING)
            ->update(['status' => AdminAction::REJECTED, 'decided_by' => $admin->id, 'decided_at' => now()]);

        if ($updated === 0) {
            throw new RuntimeException("Action #{$action->id} is no longer pending.");
        }

        return $action->fresh();
    }
}
