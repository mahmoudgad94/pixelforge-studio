<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Write actions requested by an AI agent through the admin MCP server.
        // Nothing is executed until a human admin approves it in the UI.
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // credit_adjustment
            $table->json('payload');
            $table->text('reason');
            $table->string('status')->default('pending')->index(); // pending, approved, rejected, expired
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('mcp_token_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('mcp_token_hash'));
    }
};
