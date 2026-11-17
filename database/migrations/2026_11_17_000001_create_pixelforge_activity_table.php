<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail of every conversation with Pixelforge: our API calls out, and every webhook delivery in.
        Schema::create('pixelforge_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16); // api (we called them) or webhook (they called us)
            $table->string('action');      // "POST /generations", "GET /generations/gen_x", "generation.succeeded"
            $table->unsignedSmallInteger('status_code')->nullable(); // their HTTP status, or the status we answered a webhook with
            $table->string('outcome', 32);  // ok, replayed, retryable_error, error, connection_error, processed, duplicate, invalid_signature, ...
            $table->string('error_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempt')->nullable(); // our submit attempt, or Pixelforge-Delivery-Attempt
            $table->string('event_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('detail')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index(['generation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixelforge_activity');
    }
};
