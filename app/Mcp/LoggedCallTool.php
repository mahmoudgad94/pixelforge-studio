<?php

namespace App\Mcp;

use App\Models\McpToolCall;
use Generator;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * `tools/call` with a usage log around it. Registered once in AdminServer, so every tool is logged
 * (success, tool error, unknown tool, crash) without any tool knowing about it.
 */
class LoggedCallTool extends CallTool
{
    public function __construct(private readonly McpCallLog $log) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $startedAt = hrtime(true);
        $tool = is_string($request->params['name'] ?? null) ? $request->params['name'] : null;
        $arguments = is_array($request->params['arguments'] ?? null) ? $request->params['arguments'] : [];
        $user = Auth::user();

        try {
            $response = parent::handle($request, $context);
        } catch (Throwable $e) {
            $this->log->recordCall($user, $tool, $arguments, McpToolCall::ERROR, $e->getMessage(), $startedAt);

            throw $e;
        }

        if ($response instanceof JsonRpcResponse) {
            $this->record($user, $tool, $arguments, $response, $startedAt);

            return $response;
        }

        // Streamed result: log when the stream finishes, with its final response.
        return (function () use ($response, $user, $tool, $arguments, $startedAt): Generator {
            $final = null;
            try {
                foreach ($response as $item) {
                    if ($item instanceof JsonRpcResponse && (isset($item->content['result']) || isset($item->content['error']))) {
                        $final = $item;
                    }
                    yield $item;
                }
            } catch (Throwable $e) {
                $this->log->recordCall($user, $tool, $arguments, McpToolCall::ERROR, $e->getMessage(), $startedAt);

                throw $e;
            }
            $this->record($user, $tool, $arguments, $final, $startedAt);
        })();
    }

    private function record(mixed $user, ?string $tool, array $arguments, ?JsonRpcResponse $response, int $startedAt): void
    {
        $content = $response?->content ?? [];
        $result = is_array($content['result'] ?? null) ? $content['result'] : [];

        if (isset($content['error'])) {
            $error = is_array($content['error']) ? ($content['error']['message'] ?? 'Error') : 'Error';
        } elseif (($result['isError'] ?? false) === true) {
            $error = collect($result['content'] ?? [])->pluck('text')->filter()->implode(' ') ?: 'Tool returned an error.';
        } else {
            $error = null;
        }

        $this->log->recordCall($user, $tool, $arguments, $error === null ? McpToolCall::OK : McpToolCall::ERROR, $error, $startedAt);
    }
}
