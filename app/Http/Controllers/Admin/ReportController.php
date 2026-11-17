<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\SpendReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, SpendReport $report): Response
    {
        return Inertia::render('Admin/Report', $report->build($request->query('start'), $request->query('end')));
    }

    /** The generations inside one day of the report, loaded when the admin opens that day. */
    public function day(string $date, SpendReport $report): JsonResponse
    {
        $rows = $report->dayDetail($date);
        abort_if($rows === null, 404);

        return response()->json(['date' => $date, 'generations' => $rows]);
    }
}
