<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for missed or delayed webhooks.
Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();

Artisan::command('mcp:token {email}', function (string $email) {
    $user = User::where('email', $email)->where('is_admin', true)->first();
    if (! $user) {
        $this->error('No admin user with that email.');

        return 1;
    }
    $token = 'pfs_'.Str::random(40);
    $user->forceFill(['mcp_token_hash' => hash('sha256', $token)])->save();
    $this->info('MCP token (shown once, replaces any previous one):');
    $this->line($token);
})->purpose('Create an admin MCP bearer token');
