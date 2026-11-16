<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Sent to Pixelforge as Idempotency-Key and metadata.generation_uuid.
            $table->uuid('uuid')->unique();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status')->index(); // pending, queued, processing, succeeded, failed

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Estimates (user price, picodollars) shown before submit.
            $table->bigInteger('estimated_min_price_picos');
            $table->bigInteger('estimated_price_picos');
            $table->bigInteger('estimated_max_price_picos');
            $table->bigInteger('hold_picos');

            // Actuals, filled once the generation succeeds.
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->bigInteger('provider_cost_picos')->nullable();
            $table->bigInteger('price_picos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
