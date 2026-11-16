<?php

namespace App\Http\Controllers;

use App\Pixelforge\Pricing;
use App\Support\Money;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Pricing $pricing): Response
    {
        // Real prices for a typical 60-character prompt, from the same pricing code that bills users.
        $examples = collect(Pricing::QUALITIES)->map(function (string $quality) use ($pricing) {
            $e = $pricing->estimate(str_repeat('x', 60), '1024x1024', $quality, 0);

            return ['quality' => $quality, 'from' => Money::format($e['min']['price']), 'to' => Money::format($e['max']['price'])];
        });

        return Inertia::render('Home', ['examples' => $examples, 'markupPercent' => $pricing->markupPercent()]);
    }
}
