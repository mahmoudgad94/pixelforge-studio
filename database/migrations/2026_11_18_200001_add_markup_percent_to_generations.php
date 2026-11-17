<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // The markup shown to the user when they pressed Generate. Settling uses this, so changing
            // PIXELFORGE_MARKUP_PERCENT never re-prices an image that is already running.
            $table->unsignedSmallInteger('markup_percent')->nullable()->after('quality');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('markup_percent');
        });
    }
};
