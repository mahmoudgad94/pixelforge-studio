<?php

use App\Models\McpToolCall;
use App\Models\User;
use App\Pixelforge\Ledger;
use App\Support\Money;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for missed or delayed webhooks.
Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();

// Keep the MCP usage log to 90 days.
Schedule::command('model:prune', ['--model' => [McpToolCall::class]])->daily();

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

Artisan::command('demo:user {email} {password}', function (string $email, string $password) {
    $user = User::firstOrNew(['email' => $email]);
    $user->forceFill(['name' => 'Reviewer', 'password' => $password, 'is_admin' => true, 'email_verified_at' => now()])->save();
    $user->refresh();
    if ($user->balance_picos < Money::fromUsd('1')) {
        app(Ledger::class)->purchase($user, Money::fromUsd('10'));
    }
    $this->info("Demo admin {$email} ready with ".Money::format($user->fresh()->balance_picos).' credits.');
})->purpose('Create or reset the demo reviewer account');
