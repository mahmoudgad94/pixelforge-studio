<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Reports\GenerationTimeline;
use App\Reports\SpendReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Same data as the admin pages and the MCP tools, as plain JSON for scripts.
 * Auth: Authorization: Bearer <admin token> (static token from the Approvals page, or an OAuth token).
 */
class AdminApiController extends Controller
{
    public function report(Request $request, SpendReport $report): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json($report->build($data['start_date'] ?? null, $data['end_date'] ?? null));
    }

    /** Every generation that finished on one UTC day (same rows as the report's day drill-down). */
    public function day(string $date, SpendReport $report): JsonResponse
    {
        $rows = $report->dayDetail($date);
        abort_if($rows === null, 404);

        return response()->json(['date' => $date, 'generations' => $rows]);
    }

    public function generation(string $id, GenerationTimeline $timeline): JsonResponse
    {
        $generation = Generation::query()->where('pixelforge_id', $id)->orWhere('id', ctype_digit($id) ? (int) $id : 0)->firstOrFail();

        return response()->json($timeline->build($generation));
    }
}
