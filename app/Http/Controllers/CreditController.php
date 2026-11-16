<?php

namespace App\Http\Controllers;

use App\Pixelforge\Ledger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditController extends Controller
{
    /** Credit packs a user can buy, in whole USD. */
    public const PACKS = [5, 10, 25];

    /** Fake checkout. The user confirms a pack in a dialog; the key makes a double submit harmless. */
    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', Rule::in(self::PACKS)],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $bought = $ledger->purchase($request->user(), Money::fromUsd((string) $data['amount']), $data['idempotency_key']);

        return back()->with('status', $bought
            ? 'Added '.Money::format(Money::fromUsd((string) $data['amount'])).' of credits.'
            : 'That purchase was already processed, nothing was charged twice.');
    }
}
