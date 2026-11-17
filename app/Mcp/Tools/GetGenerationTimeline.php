<?php

namespace App\Mcp\Tools;

use App\Models\Generation;
use App\Reports\GenerationTimeline;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Everything that happened to one generation: time spent in each stage, every API call we made to Pixelforge (status, error code, duration, retries) and every webhook it sent us (event, delivery attempt, sender IP, our answer, duplicates ignored), plus cost, charge and profit.')]
class GetGenerationTimeline extends Tool
{
    public function handle(Request $request, GenerationTimeline $timeline): Response|ResponseFactory
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:64']]);
        $id = $data['id'];

        $generation = Generation::query()->where('pixelforge_id', $id)->orWhere('id', ctype_digit($id) ? (int) $id : 0)->first();
        if (! $generation) {
            return Response::error("No generation {$id}.");
        }

        return Response::structured($timeline->build($generation));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Our generation number (e.g. "42") or the Pixelforge id (e.g. "gen_...").')->required(),
        ];
    }
}
