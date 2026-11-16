<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_shows_real_prices_and_app_needs_login()
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('Home')->has('examples', 3));
        $this->get('/generate')->assertRedirect('/login');
    }

    public function test_login_page_prefills_demo_account_only_when_configured()
    {
        config(['app.demo_login' => ['email' => null, 'password' => null]]);
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('demo', null));

        config(['app.demo_login' => ['email' => 'reviewer@demo.test', 'password' => 'demo-pass']]);
        $this->get('/login')->assertInertia(fn ($page) => $page->where('demo.email', 'reviewer@demo.test'));
    }
}
