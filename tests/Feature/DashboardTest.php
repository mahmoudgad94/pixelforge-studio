<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Http::fake(['*/v1/pricing' => Http::response([], 500)]);

        $this->get('/dashboard')->assertRedirect('/generate');
        $this->get('/generate')->assertOk();
        $this->get('/history')->assertOk();
        $this->get('/admin/report')->assertForbidden();
    }
}
