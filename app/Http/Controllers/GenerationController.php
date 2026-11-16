<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Ledger;
use App\Pixelforge\Pricing;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(Request $request, Pricing $pricing): Response
    {
        return Inertia::render('Generations/Create', [
            'pricing' => $pricing->table(),
            'markupPercent' => $pricing->markupPercent(),
            'sizes' => Pricing::SIZES,
            'qualities' => Pricing::QUALITIES,
            'balance' => Money::format($request->user()->balance_picos),
            'balancePicos' => (string) $request->user()->balance_picos,
        ]);
    }

    public function store(Request $request, Pricing $pricing, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(Pricing::SIZES)],
            'quality' => ['required', Rule::in(Pricing::QUALITIES)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ]);

        $references = array_values($data['reference_images'] ?? []);
        $estimate = $pricing->estimate($data['prompt'], $data['size'], $data['quality'], count($references));

        $generation = DB::transaction(function () use ($request, $data, $references, $estimate, $ledger) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            $generation = new Generation([
                'uuid' => (string) Str::uuid(),
                'status' => Generation::PENDING,
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $references,
                'estimated_min_price_picos' => $estimate['min']['price'],
                'estimated_price_picos' => $estimate['expected']['price'],
                'estimated_max_price_picos' => $estimate['max']['price'],
                // Hold the worst case so a balance can never go negative.
                'hold_picos' => $estimate['max']['price'],
            ]);
            $generation->user()->associate($user);
            $generation->save();

            if (! $ledger->hold($user, $generation)) {
                throw ValidationException::withMessages([
                    'prompt' => 'Not enough credits. This generation can cost up to '.Money::format($estimate['max']['price']).
                        ' and your balance is '.Money::format($user->balance_picos).'.',
                ]);
            }

            return $generation;
        });

        SubmitGeneration::dispatch($generation->id)->afterCommit();

        return to_route('generations.index')->with('status', 'Generation queued.');
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest('id')->paginate(20);

        return Inertia::render('Generations/Index', [
            'generations' => $generations->through(fn (Generation $g) => $g->toFrontend()),
            'balance' => Money::format($request->user()->balance_picos),
        ]);
    }
}
