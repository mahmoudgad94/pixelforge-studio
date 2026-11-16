<?php

namespace App\Mcp\Tools;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('A user\'s credit balance and their latest ledger entries (purchases, holds, releases, charges, adjustments).')]
class GetUserCredits extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            return Response::error('No user with that email.');
        }

        return Response::structured([
            'email' => $user->email,
            'balance' => Money::format($user->balance_picos),
            'balance_exact' => Money::toUsdString($user->balance_picos),
            'ledger' => CreditTransaction::where('user_id', $user->id)->latest('id')->limit(15)->get()
                ->map(fn ($t) => [
                    'type' => $t->type,
                    'generation_id' => $t->generation_id,
                    'amount' => Money::toUsdString($t->amount_picos),
                    'balance_after' => Money::toUsdString($t->balance_after_picos),
                    'at' => $t->created_at->toIso8601String(),
                ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['email' => $schema->string()->required()];
    }
}
