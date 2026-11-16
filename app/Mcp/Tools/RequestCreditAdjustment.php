<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Pixelforge\AdminActions;
use App\Support\Money;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Ask to add (positive) or remove (negative) credits for a user, e.g. a goodwill refund. Does NOT change the balance: it creates a request a human admin must approve in the app within 24 hours.')]
class RequestCreditAdjustment extends Tool
{
    public function handle(Request $request, AdminActions $actions): Response|ResponseFactory
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'amount_usd' => ['required', 'string', 'regex:/^-?\d{1,4}(\.\d{1,9})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            return Response::error('No user with that email.');
        }

        try {
            $amount = Money::fromUsd($data['amount_usd']);
        } catch (MathException) {
            return Response::error('amount_usd must be a decimal like "2.50" or "-1".');
        }
        if ($amount === 0) {
            return Response::error('amount_usd cannot be zero.');
        }

        $action = $actions->requestCreditAdjustment($request->user(), $user, $amount, $data['reason']);

        return Response::structured([
            'request_id' => $action->id,
            'status' => $action->status,
            'amount' => Money::format($amount),
            'email' => $user->email,
            'approve_at' => route('admin.approvals.index'),
            'expires_at' => $action->expires_at->toIso8601String(),
            'note' => 'Nothing has changed yet. A human admin must approve this request.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->required(),
            'amount_usd' => $schema->string()->description('Decimal string, e.g. "2.50" or "-1.00". Max 9 decimals.')->required(),
            'reason' => $schema->string()->description('Why, shown to the approving admin.')->required(),
        ];
    }
}
