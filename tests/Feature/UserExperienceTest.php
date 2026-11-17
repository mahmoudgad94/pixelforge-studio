<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function generation(User $user, string $status, array $extra = []): Generation
    {
        $g = new Generation(array_merge([
            'uuid' => (string) Str::uuid(),
            'status' => $status,
            'prompt' => 'a red fox [fail]',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png'],
            'estimated_min_price_picos' => 1,
            'estimated_price_picos' => 2,
            'estimated_max_price_picos' => 3,
            'hold_picos' => 0,
        ], $extra));
        $g->user()->associate($user);
        $g->save();

        return $g;
    }

    public function test_buying_credits_needs_a_confirmed_pack_and_a_key(): void
    {
        $user = User::factory()->create(['balance_picos' => 0]);

        $this->actingAs($user)->post('/credits', [])->assertSessionHasErrors(['amount', 'idempotency_key']);
        $this->actingAs($user)->post('/credits', ['amount' => 1000, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('amount');

        $this->assertSame(0, $user->fresh()->balance_picos);
    }

    public function test_a_double_submitted_purchase_is_charged_once(): void
    {
        $user = User::factory()->create(['balance_picos' => 0]);
        $key = (string) Str::uuid();

        $this->actingAs($user)->post('/credits', ['amount' => 25, 'idempotency_key' => $key])->assertSessionHas('status', 'Added $25.00 of credits.');
        $this->actingAs($user)->post('/credits', ['amount' => 25, 'idempotency_key' => $key])->assertSessionHas('status');

        $this->assertSame(Money::fromUsd('25'), $user->fresh()->balance_picos);
        $this->assertSame(1, CreditTransaction::where('type', CreditTransaction::PURCHASE)->count());
    }

    public function test_status_poll_only_returns_the_users_own_generations(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->generation($me, Generation::SUCCEEDED, ['price_picos' => Money::fromUsd('0.5')]);
        $theirs = $this->generation($other, Generation::PROCESSING);

        $this->actingAs($me)->getJson("/generations/updates?ids[]={$mine->id}&ids[]={$theirs->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id)
            ->assertJsonPath('0.status', 'succeeded')
            ->assertJsonPath('0.price', '$0.50');
    }

    public function test_every_page_knows_which_generations_are_still_running(): void
    {
        $user = User::factory()->create();
        $running = $this->generation($user, Generation::PROCESSING);
        $this->generation($user, Generation::SUCCEEDED);

        $this->actingAs($user)->get('/history')->assertInertia(fn ($page) => $page->where('inFlight', [$running->id]));
    }

    public function test_retry_prefills_the_form_from_a_failed_generation_but_only_for_its_owner(): void
    {
        $owner = User::factory()->create();
        $failed = $this->generation($owner, Generation::FAILED);

        $this->actingAs($owner)->get("/generate?retry={$failed->id}")->assertInertia(fn ($page) => $page
            ->where('prefill.prompt', 'a red fox [fail]')
            ->where('prefill.size', '1536x1024')
            ->where('prefill.quality', 'high')
            ->where('prefill.reference_images', ['https://example.com/a.png']));

        $this->actingAs(User::factory()->create())->get("/generate?retry={$failed->id}")->assertInertia(fn ($page) => $page->where('prefill', null));
    }

    public function test_home_page_is_public_and_uses_real_prices(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Home')
            ->where('markupPercent', 25)
            ->where('examples.0.quality', 'low'));
    }

    public function test_a_device_image_is_uploaded_and_returned_as_a_public_url(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $res = $this->actingAs($user)->post('/references', ['image' => UploadedFile::fake()->image('cat.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertStringContainsString('/storage/references/'.$user->id.'/', $res->json('url'));
        $this->assertCount(1, Storage::disk('public')->allFiles('references/'.$user->id));

        $this->actingAs($user)->post('/references', ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_an_admin_can_create_and_revoke_an_mcp_token_from_the_approvals_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/approvals')->assertInertia(fn ($page) => $page
            ->where('mcp.hasToken', false)->where('mcp.newToken', null)->where('mcp.url', url('/mcp/admin')));

        $this->actingAs($admin)->post('/admin/mcp-token')->assertSessionHas('mcp_token');
        $token = session('mcp_token');
        $this->assertSame(hash('sha256', $token), $admin->fresh()->mcp_token_hash);

        $this->actingAs($admin)->delete('/admin/mcp-token');
        $this->assertNull($admin->fresh()->mcp_token_hash);

        $this->actingAs(User::factory()->create())->post('/admin/mcp-token')->assertForbidden();
    }
}
