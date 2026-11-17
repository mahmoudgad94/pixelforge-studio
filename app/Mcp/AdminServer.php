<?php

namespace App\Mcp;

use App\Mcp\Tools\FindGenerations;
use App\Mcp\Tools\GetActionStatus;
use App\Mcp\Tools\GetDayGenerations;
use App\Mcp\Tools\GetGenerationTimeline;
use App\Mcp\Tools\GetSpendReport;
use App\Mcp\Tools\GetUserCredits;
use App\Mcp\Tools\RequestCreditAdjustment;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Pixelforge Studio Admin')]
#[Version('1.0.0')]
#[Instructions(<<<'MD'
    Admin tools for Pixelforge Studio. Read tools answer questions about spend, profit, generations and balances.
    Money is USD; costs have up to 9 decimals. Days are UTC days a generation completed.
    Write tools never change data directly: they create a request that a human admin must approve in the app
    (Admin > Approvals). Tell the user the request id and that it is waiting for approval.
    MD)]
class AdminServer extends Server
{
    protected array $tools = [
        GetSpendReport::class,
        GetDayGenerations::class,
        FindGenerations::class,
        GetGenerationTimeline::class,
        GetUserCredits::class,
        RequestCreditAdjustment::class,
        GetActionStatus::class,
    ];

    /** Every tools/call goes through the usage log (table mcp_tool_calls). */
    protected function boot(): void
    {
        $this->addMethod('tools/call', LoggedCallTool::class);
    }
}
