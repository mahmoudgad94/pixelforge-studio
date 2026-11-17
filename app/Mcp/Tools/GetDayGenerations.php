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
#[Description('Every generation that finished on one UTC day: user, prompt, size, quality, tokens, estimate, charge, provider cost and profit. Failed ones are listed as free. The same view as clicking a day in the admin report.')]
class GetDayGenerations extends Tool
{
    public function handle(Request $request, SpendReport $report): ResponseFactory
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        $rows = $report->dayDetail($data['date']) ?? [];

        return Response::structured(['date' => $data['date'], 'count' => count($rows), 'generations' => $rows]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('UTC date YYYY-MM-DD, the day the generations completed (as Pixelforge bills).')->required(),
        ];
    }
}
