<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // completed_at alone is already indexed. The admin report filters succeeded rows by completed_at range and groups by day and user.
            $table->index(['status', 'completed_at']);
            $table->index(['user_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropIndex(['user_id', 'completed_at']);
        });
    }
};
