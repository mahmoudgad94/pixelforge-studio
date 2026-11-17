<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per call an AI client makes to the admin MCP server, including refused requests.
        Schema::create('mcp_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client'); // OAuth client name ("Claude", "Cursor"), "personal token" or "unknown"
            $table->string('tool')->nullable(); // null for a refused request that never reached a tool
            $table->string('arguments_summary', 500)->nullable(); // truncated, secrets removed
            $table->string('outcome', 16); // ok, error, refused
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index('created_at');
            $table->index(['tool', 'created_at']);
            $table->index(['outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
    }
};
