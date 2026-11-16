<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Pixelforge\AdminActions;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ApprovalController extends Controller
{
    public function index(): Response
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
        ]);
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
