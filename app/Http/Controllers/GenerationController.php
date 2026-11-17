<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Ledger;
use App\Pixelforge\Pricing;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
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
        // "Retry" on a failed card opens this page pre-filled, so the user sees the fresh estimate before paying.
        $retry = $request->integer('retry') ? $request->user()->generations()->find($request->integer('retry')) : null;

        return Inertia::render('Generations/Create', [
            'prefill' => $retry ? [
                'prompt' => $retry->prompt,
                'size' => $retry->size,
                'quality' => $retry->quality,
                'reference_images' => $retry->reference_images ?? [],
            ] : null,
            'pricing' => $pricing->table(),
            'markupPercent' => $pricing->markupPercent(),
            'sizes' => Pricing::SIZES,
            'qualities' => Pricing::QUALITIES,
            'balance' => Money::format($request->user()->balance_picos),
            'balancePicos' => (string) $request->user()->balance_picos,
        ]);
    }

    /** Generations one user may have waiting or running at once. */
    public const MAX_IN_FLIGHT = 10;

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

            // Pixelforge runs 3 at a time for the whole app; one user should not fill the queue for everyone.
            $inFlight = Generation::query()->where('user_id', $user->id)
                ->whereIn('status', [Generation::PENDING, Generation::QUEUED, Generation::PROCESSING])->count();
            if ($inFlight >= self::MAX_IN_FLIGHT) {
                throw ValidationException::withMessages([
                    'prompt' => 'You already have '.$inFlight.' generations in progress. Wait for one to finish, then try again.',
                ]);
            }

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

        return to_route('generations.index')->with('status', 'Generation started. You can leave this page, we will notify you when it is ready.');
    }

    /** Lightweight status poll for the in-flight generations the browser is watching (no full page reload). */
    public function updates(Request $request): JsonResponse
    {
        $ids = array_slice(array_map('intval', (array) $request->query('ids', [])), 0, 50);

        return response()->json(
            $request->user()->generations()->whereIn('id', $ids)
                ->get(['id', 'status', 'prompt', 'price_picos'])
                ->map(fn (Generation $g) => [
                    'id' => $g->id,
                    'status' => $g->status,
                    'prompt' => Str::limit($g->prompt, 80),
                    'price' => Money::format($g->price_picos),
                ])
        );
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
