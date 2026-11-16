<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id')->primary(); // Pixelforge evt_ id: dedupe key
            $table->string('type');
            $table->string('pixelforge_generation_id')->nullable()->index();
            $table->json('payload');
            $table->unsignedInteger('deliveries')->default(1);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
