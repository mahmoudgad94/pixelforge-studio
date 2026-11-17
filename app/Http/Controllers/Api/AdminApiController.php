<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Generation;
use App\Models\McpToolCall;
use App\Reports\GenerationTimeline;
use App\Reports\SpendReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

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

    /** MCP usage log: every tool call (and refused request) from AI clients, newest first. */
    public function mcpCalls(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tool' => ['nullable', 'string', 'max:190'],
            'outcome' => ['nullable', 'in:'.implode(',', McpToolCall::OUTCOMES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->page(
            McpToolCall::query()->with('user:id,name,email')->filter($data['tool'] ?? null, $data['outcome'] ?? null)->latest('id')
                ->paginate((int) ($data['per_page'] ?? 25))->withQueryString()
                ->through(fn (McpToolCall $c) => $c->present())
        ));
    }

    /** Every request an AI made for a human to approve, whatever its status, newest first. */
    public function requests(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', [AdminAction::PENDING, AdminAction::APPROVED, AdminAction::REJECTED, AdminAction::EXPIRED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        AdminAction::expireOverdue();

        return response()->json($this->page(
            AdminAction::query()->with(['requester:id,name', 'decider:id,name'])
                ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest('id')
                ->paginate((int) ($data['per_page'] ?? 25))->withQueryString()
                ->through(fn (AdminAction $a) => $a->present())
        ));
    }

    /** @return array{data: array, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    private function page(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ];
    }
}
