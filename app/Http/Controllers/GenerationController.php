<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Ledger;
use App\Pixelforge\Pricing;
use App\Reports\GenerationProgress;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

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

    public function show(Request $request, Generation $generation, GenerationProgress $progress): Response
    {
        $this->ownedBy($request, $generation);

        return Inertia::render('Generations/Show', ['generation' => $progress->build($generation)]);
    }

    /** Fetches the image server-side (it lives on another origin) and sends it as a file download. */
    public function download(Request $request, Generation $generation): HttpResponse|JsonResponse
    {
        $this->ownedBy($request, $generation);
        abort_unless($generation->status === Generation::SUCCEEDED && $generation->image_url, 404);

        if ($generation->image_url_expires_at?->isPast()) {
            return response()->json(['message' => 'This image link has expired. Pixelforge keeps images for 24 hours.'], 410);
        }

        $unavailable = fn () => response()->json(['message' => 'Could not download the image right now. Try again in a moment.'], 502);

        try {
            $upstream = Http::connectTimeout(5)->timeout(20)->get($generation->image_url);
        } catch (ConnectionException) {
            return $unavailable();
        }

        $type = strtolower(trim(explode(';', (string) $upstream->header('Content-Type'))[0]));
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$type] ?? null;
        if (! $upstream->successful() || $extension === null) {
            return $unavailable();
        }

        $slug = Str::of($generation->prompt)->words(6, '')->slug()->limit(60, '')->toString() ?: 'image';
        $filename = "pixelforge-{$generation->id}-{$slug}.{$extension}";

        return response($upstream->body(), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, 'pixelforge-'.$generation->id.'.'.$extension),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Someone else's generation looks the same as one that does not exist. */
    private function ownedBy(Request $request, Generation $generation): void
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
    }
}
