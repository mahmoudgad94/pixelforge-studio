<?php

namespace App\Http\Controllers;

use App\Pixelforge\Ledger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    /** Fake checkout: adds $10 of credits, only after the user confirmed it in the dialog. */
    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);

        $ledger->purchase($request->user(), Money::fromUsd('10'));

        return back()->with('status', 'Added $10.00 of credits.');
    }
}
