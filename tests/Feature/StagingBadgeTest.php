<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StagingBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function visit(string $env): Assert
    {
        $this->app['env'] = $env;
        Http::fake(['*/v1/pricing' => Http::response([], 500)]);

        return Assert::fromTestResponse(
            $this->actingAs(User::factory()->create())->get('/generate')->assertOk()
        );
    }

    public function test_is_staging_is_shared_when_running_in_staging(): void
    {
        $this->visit('staging')->where('isStaging', true);
    }

    public function test_is_staging_is_false_in_production_and_local(): void
    {
        $this->visit('production')->where('isStaging', false);
        $this->visit('local')->where('isStaging', false);
    }
}
