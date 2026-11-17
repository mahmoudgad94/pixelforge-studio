<?php

namespace App\Http\Middleware;

use App\Models\Generation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            // True only when APP_ENV=staging, so the UI can flag the staging copy of the app.
            'isStaging' => app()->environment('staging'),
            'auth' => [
                'user' => $request->user(),
            ],
            // Generations still running, so any page can watch them and notify the user when they finish.
            'inFlight' => fn () => $request->user()
                ? $request->user()->generations()
                    ->whereIn('status', [Generation::PENDING, Generation::QUEUED, Generation::PROCESSING])
                    ->where('created_at', '>', now()->subDay())
                    ->pluck('id')
                : [],
            'flash' => ['status' => fn () => $request->session()->get('status')],
        ]);
    }
}
