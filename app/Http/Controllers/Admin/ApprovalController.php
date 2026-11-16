<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Pixelforge\AdminActions;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        AdminAction::query()->where('status', AdminAction::PENDING)->where('expires_at', '<', now())
            ->update(['status' => AdminAction::EXPIRED]);

        return Inertia::render('Admin/Approvals', [
            'actions' => AdminAction::query()->with(['requester:id,name', 'decider:id,name'])->latest('id')->limit(50)->get()
                ->map(fn (AdminAction $a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'email' => $a->payload['email'] ?? null,
                    'amount' => isset($a->payload['amount_picos']) ? Money::format((int) $a->payload['amount_picos']) : null,
                    'reason' => $a->reason,
                    'status' => $a->status,
                    'requested_by' => $a->requester?->name,
                    'decided_by' => $a->decider?->name,
                    'created_at' => $a->created_at->toIso8601String(),
                    'expires_at' => $a->expires_at->toIso8601String(),
                ]),
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
