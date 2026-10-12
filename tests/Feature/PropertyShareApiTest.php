<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PropertyCard;
use App\Models\PropertyShare;
use App\Models\User;
use Tests\TestCase;

class PropertyShareApiTest extends TestCase
{
    public function test_user_can_search_friend_by_email(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $friend = User::factory()->create([
            'name' => 'Ahmed Khan',
            'email' => 'friend@example.com',
        ]);

        $token = $owner->createToken('auth-token')->plainTextToken;

        // Valid friend email
        $response = $this->withToken($token)
            ->getJson('/api/users/search?email=friend@example.com');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Ahmed Khan')
            ->assertJsonPath('data.email', 'friend@example.com');

        // Cannot search own email
        $this->withToken($token)
            ->getJson('/api/users/search?email=owner@example.com')
            ->assertStatus(422);

        // Non-existent email
        $this->withToken($token)
            ->getJson('/api/users/search?email=notfound@example.com')
            ->assertNotFound();
    }

    public function test_owner_can_share_property_with_friend_with_permissions(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $friend = User::factory()->create(['email' => 'friend@example.com']);

        $token = $owner->createToken('auth-token')->plainTextToken;

        $property = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $owner->id,
            'owner_email' => $owner->email,
            'property_name' => 'Sunset Villa',
            'category' => 'Residential',
            'property_location' => 'Islamabad',
            'target_budget' => 2000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'today_expenses' => 0,
            'progress' => 0,
        ]);

        $response = $this->withToken($token)
            ->postJson("/api/properties/{$property->card_id}/share", [
                'email' => 'friend@example.com',
                'can_add_expense' => true,
                'can_view_expense' => true,
                'can_update_property' => false,
                'can_delete_property' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.shared_with_email', 'friend@example.com')
            ->assertJsonPath('data.permissions.can_add_expense', true)
            ->assertJsonPath('data.permissions.can_update_property', false);

        $this->assertDatabaseHas('property_shares', [
            'card_id' => $property->card_id,
            'owner_id' => $owner->id,
            'shared_with_user_id' => $friend->id,
            'can_add_expense' => true,
            'can_update_property' => false,
        ]);
    }

    public function test_friend_sees_shared_property_in_their_list(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $friend = User::factory()->create(['email' => 'friend@example.com']);

        $property = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $owner->id,
            'owner_email' => $owner->email,
            'property_name' => 'Sunset Villa',
            'category' => 'Residential',
            'property_location' => 'Islamabad',
            'target_budget' => 2000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'today_expenses' => 0,
            'progress' => 0,
        ]);

        PropertyShare::create([
            'card_id' => $property->card_id,
            'owner_id' => $owner->id,
            'shared_with_user_id' => $friend->id,
            'shared_with_email' => $friend->email,
            'can_add_expense' => true,
            'can_view_expense' => true,
            'can_update_property' => false,
            'can_delete_property' => false,
        ]);

        $friendToken = $friend->createToken('auth-token')->plainTextToken;

        $response = $this->withToken($friendToken)
            ->getJson('/api/properties');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.card_id', $property->card_id)
            ->assertJsonPath('data.0.is_owner', false)
            ->assertJsonPath('data.0.role', 'friend')
            ->assertJsonPath('data.0.permissions.can_add_expense', true)
            ->assertJsonPath('data.0.permissions.can_update_property', false);
    }

    public function test_friend_with_permission_can_add_expense_to_shared_property(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $friend = User::factory()->create(['email' => 'friend@example.com']);

        $property = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $owner->id,
            'owner_email' => $owner->email,
            'property_name' => 'Sunset Villa',
            'category' => 'Residential',
            'property_location' => 'Islamabad',
            'target_budget' => 2000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'today_expenses' => 0,
            'progress' => 0,
        ]);

        PropertyShare::create([
            'card_id' => $property->card_id,
            'owner_id' => $owner->id,
            'shared_with_user_id' => $friend->id,
            'shared_with_email' => $friend->email,
            'can_add_expense' => true,
            'can_view_expense' => true,
            'can_update_property' => false,
            'can_delete_property' => false,
        ]);

        $friendToken = $friend->createToken('auth-token')->plainTextToken;

        $response = $this->withToken($friendToken)
            ->postJson('/api/expenses', [
                'card_id' => $property->card_id,
                'amount' => 7500,
                'category' => 'Electricity Bill',
                'date' => '2026-10-06',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Expense created successfully')
            ->assertJsonPath('data.amount', 7500);

        $property->refresh();
        $this->assertEquals(7500, (float) $property->total_expense);
    }

    public function test_friend_without_permission_cannot_add_expense(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $friend = User::factory()->create(['email' => 'friend@example.com']);

        $property = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $owner->id,
            'owner_email' => $owner->email,
            'property_name' => 'Sunset Villa',
            'category' => 'Residential',
            'property_location' => 'Islamabad',
            'target_budget' => 2000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'today_expenses' => 0,
            'progress' => 0,
        ]);

        PropertyShare::create([
            'card_id' => $property->card_id,
            'owner_id' => $owner->id,
            'shared_with_user_id' => $friend->id,
            'shared_with_email' => $friend->email,
            'can_add_expense' => false,
            'can_view_expense' => true,
            'can_update_property' => false,
            'can_delete_property' => false,
        ]);

        $friendToken = $friend->createToken('auth-token')->plainTextToken;

        $response = $this->withToken($friendToken)
            ->postJson('/api/expenses', [
                'card_id' => $property->card_id,
                'amount' => 5000,
                'category' => 'Plumbing',
                'date' => '2026-10-06',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to add expenses to this property.');
    }

    public function test_friend_cannot_update_property_without_update_permission(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $friend = User::factory()->create(['email' => 'friend@example.com']);

        $property = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $owner->id,
            'owner_email' => $owner->email,
            'property_name' => 'Original Name',
            'category' => 'Residential',
            'property_location' => 'Lahore',
            'target_budget' => 1000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'today_expenses' => 0,
            'progress' => 0,
        ]);

        PropertyShare::create([
            'card_id' => $property->card_id,
            'owner_id' => $owner->id,
            'shared_with_user_id' => $friend->id,
            'shared_with_email' => $friend->email,
            'can_add_expense' => true,
            'can_view_expense' => true,
            'can_update_property' => false,
            'can_delete_property' => false,
        ]);

        $friendToken = $friend->createToken('auth-token')->plainTextToken;

        $this->withToken($friendToken)
            ->putJson("/api/properties/{$property->card_id}", [
                'property_name' => 'Hacked Name',
            ])
            ->assertForbidden();
    }
}
