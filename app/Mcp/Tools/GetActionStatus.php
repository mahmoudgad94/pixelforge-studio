<?php

namespace App\Mcp\Tools;

use App\Models\AdminAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Check whether a requested write action was approved, rejected or has expired.')]
class GetActionStatus extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['request_id' => ['required', 'integer']]);
        $action = AdminAction::with('decider:id,name')->find($data['request_id']);

        if (! $action) {
            return Response::error('No request with that id.');
        }

        $status = $action->status === AdminAction::PENDING && $action->expires_at->isPast() ? AdminAction::EXPIRED : $action->status;

        return Response::structured([
            'request_id' => $action->id,
            'status' => $status,
            'decided_by' => $action->decider?->name,
            'decided_at' => $action->decided_at?->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['request_id' => $schema->integer()->required()];
    }
}
