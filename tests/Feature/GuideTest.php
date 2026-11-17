<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_product_tour_is_public(): void
    {
        $this->get('/guide')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Guide'));
    }

    public function test_every_guide_screenshot_exists(): void
    {
        $vue = file_get_contents(resource_path('js/pages/Guide.vue'));
        preg_match_all("/shot\\(\\s*'([a-z-]+)'/", $vue, $m);

        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $name) {
            $this->assertFileExists(public_path("images/guide/{$name}.webp"));
        }
    }
}
