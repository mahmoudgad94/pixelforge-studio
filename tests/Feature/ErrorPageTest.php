<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_url_shows_the_branded_404_page(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('ErrorPage')->where('status', 404));
    }

    public function test_a_missing_generation_shows_the_branded_404_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/history/999999')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('ErrorPage')->where('status', 404));
    }

    public function test_a_non_admin_gets_the_branded_403_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/admin/report')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('ErrorPage')->where('status', 403));
    }

    public function test_api_callers_still_get_json(): void
    {
        $this->getJson('/api/no-such-endpoint')->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/api/no-such-endpoint')->assertNotFound()->assertHeaderMissing('X-Inertia');
    }
}
