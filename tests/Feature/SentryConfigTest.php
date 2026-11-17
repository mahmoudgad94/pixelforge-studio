<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SentryConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_dsn_the_browser_gets_no_sentry_config(): void
    {
        config(['sentry.dsn' => null]);

        $this->get('/login')->assertOk()->assertDontSee('name="sentry-dsn"', false);
    }

    public function test_with_a_dsn_the_page_carries_dsn_environment_and_only_the_user_id(): void
    {
        config(['sentry.dsn' => 'https://public@o1.ingest.sentry.io/1', 'sentry.environment' => 'staging']);
        $user = User::factory()->create(['email' => 'private@example.com']);

        $this->actingAs($user)->get('/generate')->assertOk()
            ->assertSee('<meta name="sentry-dsn" content="https://public@o1.ingest.sentry.io/1">', false)
            ->assertSee('<meta name="sentry-environment" content="staging">', false)
            ->assertSee('<meta name="sentry-user" content="'.$user->id.'">', false)
            ->assertDontSee('private@example.com" name', false);
    }
}
