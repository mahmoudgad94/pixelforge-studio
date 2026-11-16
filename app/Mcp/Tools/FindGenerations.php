<?php

namespace App\Mcp\Tools;

use App\Models\Generation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Find recent generations, optionally by user email and status. Returns status, estimated and charged price, errors.')]
class FindGenerations extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate([
            'email' => ['nullable', 'email'],
            'status' => ['nullable', 'in:pending,queued,processing,succeeded,failed'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $generations = Generation::query()
            ->with('user:id,email')
            ->when($data['email'] ?? null, fn ($q, $email) => $q->whereRelation('user', 'email', $email))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->limit($data['limit'] ?? 20)
            ->get()
            ->map(fn (Generation $g) => [
                'id' => $g->id,
                'pixelforge_id' => $g->pixelforge_id,
                'user' => $g->user?->email,
                ...collect($g->toFrontend())->only(['status', 'prompt', 'size', 'quality', 'estimated_price', 'price_exact', 'error', 'created_at', 'completed_at'])->all(),
            ]);

        return Response::structured(['generations' => $generations->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->description('Only this user.'),
            'status' => $schema->string()->enum(['pending', 'queued', 'processing', 'succeeded', 'failed']),
            'limit' => $schema->integer()->description('1 to 50, default 20.'),
        ];
    }
}
