<?php

namespace App\Mcp\Tools;

use App\Reports\SpendReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Credit spend, provider cost and profit per UTC day and per user, with each day checked against Pixelforge usage records.')]
class GetSpendReport extends Tool
{
    public function handle(Request $request, SpendReport $report): ResponseFactory
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $result = $report->build($data['start_date'] ?? null, $data['end_date'] ?? null);
        // Only days with activity, to keep the answer short.
        $result['days'] = array_values(array_filter($result['days'], fn ($d) => $d['generations'] > 0 || ($d['pixelforge_generations'] ?? 0) > 0));

        return Response::structured($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'start_date' => $schema->string()->description('UTC date YYYY-MM-DD. Defaults to 13 days before end_date.'),
            'end_date' => $schema->string()->description('UTC date YYYY-MM-DD. Defaults to today.'),
        ];
    }
}
