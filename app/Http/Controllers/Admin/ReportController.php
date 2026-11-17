<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\SpendReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, SpendReport $report): Response
    {
        return Inertia::render('Admin/Report', [
            ...$report->build($request->query('start'), $request->query('end'), max(1, (int) $request->query('users_page', 1))),
            'refreshNotice' => $request->session()->get('usage_refresh'),
        ]);
    }

    /** Re-check usage with Pixelforge now, ignoring the cache. A short lock stops it being spammed. */
    public function refresh(Request $request, SpendReport $report): RedirectResponse
    {
        $result = $report->build($request->input('start'), $request->input('end'), 1, refreshUsage: true);

        return to_route('admin.report', ['start' => $result['start'], 'end' => $result['end']])
            ->with('usage_refresh', $result['refreshThrottled'] ? 'throttled' : 'refreshed');
    }

    /** The generations inside one day of the report, loaded when the admin opens that day. */
    public function day(string $date, SpendReport $report): JsonResponse
    {
        $page = $report->dayPage($date, (int) request()->query('page', 1));
        abort_if($page === null, 404);

        return response()->json(['date' => $date, ...$page]);
    }
}
