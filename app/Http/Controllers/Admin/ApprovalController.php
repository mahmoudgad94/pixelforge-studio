<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\McpToolCall;
use App\Pixelforge\AdminActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ApprovalController extends Controller
{
    private const PAGE_SIZE = 10;

    public function index(Request $request): Response
    {
        AdminAction::expireOverdue();

        $filters = $request->validate([
            'tool' => ['nullable', 'string', 'max:190'],
            'outcome' => ['nullable', 'in:'.implode(',', McpToolCall::OUTCOMES)],
        ]);
        $with = ['requester:id,name', 'decider:id,name'];

        // Every prop is lazy, so the 10-second live refresh only runs the queries it asks for.
        return Inertia::render('Admin/Approvals', [
            // Waiting for a decision: always all of them, oldest first, so none can be missed.
            'pending' => fn () => AdminAction::query()->with($with)->where('status', AdminAction::PENDING)->orderBy('id')->get()
                ->map(fn (AdminAction $a) => $a->present()),
            // Everything decided or expired, newest first.
            'history' => fn () => AdminAction::query()->with($with)->where('status', '!=', AdminAction::PENDING)->latest('id')
                ->paginate(self::PAGE_SIZE, ['*'], 'history_page')->withQueryString()
                ->through(fn (AdminAction $a) => $a->present()),
            'mcpCalls' => fn () => McpToolCall::query()->with('user:id,name,email')->filter($filters['tool'] ?? null, $filters['outcome'] ?? null)
                ->latest('id')->paginate(self::PAGE_SIZE, ['*'], 'mcp_page')->withQueryString()
                ->through(fn (McpToolCall $c) => $c->present()),
            'mcpFilters' => [
                'tool' => $filters['tool'] ?? null,
                'outcome' => $filters['outcome'] ?? null,
                'tools' => fn () => McpToolCall::query()->whereNotNull('tool')->distinct()->orderBy('tool')->pluck('tool'),
            ],
            'mcp' => [
                'url' => url('/mcp/admin'),
                'hasToken' => $request->user()->mcp_token_hash !== null,
                // Shown once, right after it was created (flashed by createToken below).
                'newToken' => $request->session()->get('mcp_token'),
            ],
        ]);
    }

    /** Create (or replace) this admin's static MCP token. Only its hash is stored; the token is shown once. */
    public function createToken(Request $request): RedirectResponse
    {
        $token = 'pfs_'.Str::random(40);
        $request->user()->forceFill(['mcp_token_hash' => hash('sha256', $token)])->save();

        return back()->with('mcp_token', $token)->with('status', 'New MCP token created. Copy it now, it will not be shown again.');
    }

    public function revokeToken(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['mcp_token_hash' => null])->save();

        return back()->with('status', 'MCP token revoked. Clients using it are disconnected.');
    }

    public function approve(Request $request, AdminAction $action, AdminActions $actions): RedirectResponse
    {
        try {
            $actions->approve($action, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', "Action #{$action->id} approved and applied.");
    }

    public function reject(Request $request, AdminAction $action, AdminActions $actions): RedirectResponse
    {
        try {
            $actions->reject($action, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', "Action #{$action->id} rejected.");
    }
}
