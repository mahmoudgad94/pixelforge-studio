<?php

namespace Tests\Feature\Pixelforge;

use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_needs_explicit_confirmation()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits')->assertSessionHasErrors('confirmed');
        $this->actingAs($user)->post('/credits', ['confirmed' => false])->assertSessionHasErrors('confirmed');

        $this->assertSame(0, (int) $user->fresh()->balance_picos);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_confirmed_purchase_adds_exactly_ten_dollars()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['confirmed' => true])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(Money::fromUsd('10'), (int) $user->fresh()->balance_picos);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $user->id, 'type' => 'purchase', 'amount_picos' => Money::fromUsd('10')]);
    }
}
