<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The "advanced" side of each call: exactly what was sent and what came back, already redacted
        // and capped (see App\Pixelforge\ActivityPayload). For a webhook the "request" is what
        // Pixelforge sent us and the "response" is what we answered.
        Schema::table('pixelforge_activity', function (Blueprint $table) {
            $table->string('request_method', 10)->nullable();
            $table->text('request_url')->nullable();
            $table->json('request_headers')->nullable();
            $table->longText('request_body')->nullable();
            $table->boolean('request_body_truncated')->default(false);
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->boolean('response_body_truncated')->default(false);
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->string('error_class')->nullable();   // exception class of a connection error
            $table->text('error_message')->nullable();   // its message
        });
    }

    public function down(): void
    {
        Schema::table('pixelforge_activity', function (Blueprint $table) {
            $table->dropColumn([
                'request_method', 'request_url', 'request_headers', 'request_body', 'request_body_truncated',
                'response_headers', 'response_body', 'response_body_truncated', 'started_at', 'finished_at',
                'error_class', 'error_message',
            ]);
        });
    }
};
