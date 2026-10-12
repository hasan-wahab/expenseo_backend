<?php

namespace Tests\Feature;

use App\Models\PropertyCard;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    public function test_user_can_register_with_profile_image(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/register', [
            'name' => 'Ali Khan',
            'email' => 'ali@example.com',
            'password' => 'password123',
            'profile_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        ]);

        $response->assertStatus(201)
            ->assertExactJson([
                'message' => 'User registered successfully',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'ali@example.com',
            'name' => 'Ali Khan',
        ]);

        $user = User::where('email', 'ali@example.com')->firstOrFail();
        Storage::disk('public')->assertExists($user->profile_image);
    }

    public function test_user_can_login_and_receive_token_and_user_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Sara Ali',
            'email' => 'sara@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'sara@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => ['id', 'name', 'email', 'profile_image'],
            ])
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.created_at')
            ->assertJsonMissingPath('user.updated_at');
    }

    public function test_registration_validation_errors_are_returned_as_json_for_api_requests(): void
    {
        $this->post('/api/register', [
            'name' => 'Ali Khan',
            'email' => 'ali@example.com',
            'password' => 'password123',
            'profile_image' => 'data:image/png;base64,APNI_BASE64_IMAGE_YAHAN_PASTE_KAREIN',
        ], ['Accept' => '*/*'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_image');
    }

    public function test_registration_rejects_profile_images_larger_than_three_hundred_kilobytes(): void
    {
        $image = 'data:image/png;base64,'.base64_encode(str_repeat('a', 300 * 1024 + 1));

        $this->postJson('/api/register', [
            'name' => 'Ali Khan',
            'email' => 'ali@example.com',
            'password' => 'password123',
            'profile_image' => $image,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'profile_image' => 'The profile image must be no larger than 300 KB.',
            ]);
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/profile', [
                'name' => 'New Name',
                'email' => 'new@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully')
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.email', 'new@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->patchJson('/api/profile', ['name' => 'New Name'])
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_logout_and_revoke_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Logout successful',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => hash('sha256', explode('|', $token, 2)[1]),
        ]);

        // Naya request nayi authentication guard state ke saath test karta hai.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->patchJson('/api/profile', ['name' => 'No longer authorized'])
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_a_property_card(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/properties', [
                'property_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
                'property_name' => 'Family House',
                'category' => 'Residential',
                'property_location' => 'Lahore',
                'target_budget' => 1250000,
                'user_email' => 'owner@example.com',
            ])
            ->assertCreated()
            ->assertExactJson([
                'message' => 'Property card created successfully',
            ]);

        $propertyCard = PropertyCard::firstOrFail();
        $this->assertSame($user->id, $propertyCard->user_id);
        $this->assertSame('owner@example.com', $propertyCard->owner_email);
        Storage::disk('public')->assertExists($propertyCard->property_image);
    }

    public function test_property_card_creation_requires_authentication_and_matching_email(): void
    {
        $payload = [
            'property_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
            'property_name' => 'Family House',
            'category' => 'Residential',
            'property_location' => 'Lahore',
            'target_budget' => 1250000,
            'user_email' => 'owner@example.com',
        ];

        $this->postJson('/api/properties', $payload)->assertUnauthorized();

        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/properties', array_merge($payload, [
                'user_email' => 'someone-else@example.com',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_email');
    }

    public function test_authenticated_user_can_create_a_property_card_without_an_image(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/properties', [
                'property_name' => 'Family House',
                'category' => 'Residential',
                'property_location' => 'Lahore',
                'target_budget' => 1250000,
                'user_email' => 'owner@example.com',
            ])
            ->assertCreated()
            ->assertExactJson([
                'message' => 'Property card created successfully',
            ]);

        $this->assertDatabaseHas('property_cards', [
            'user_id' => $user->id,
            'owner_email' => 'owner@example.com',
            'property_name' => 'Family House',
            'property_image' => null,
        ]);
    }

    public function test_authenticated_user_can_get_their_property_cards_with_pagination(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $otherUser = User::factory()->create();

        foreach (['House One', 'House Two', 'House Three'] as $propertyName) {
            PropertyCard::create([
                'card_id' => (string) str()->uuid(),
                'user_id' => $user->id,
                'owner_email' => $user->email,
                'property_name' => $propertyName,
                'category' => 'Residential',
                'property_location' => 'Lahore',
                'target_budget' => 100000,
                'total_expense' => 0,
                'monthly_expense' => 0,
                'progress' => 0,
            ]);
        }

        PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $otherUser->id,
            'owner_email' => $otherUser->email,
            'property_name' => 'Other User House',
            'category' => 'Residential',
            'property_location' => 'Karachi',
            'target_budget' => 200000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'progress' => 0,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/properties?per_page=2&page=1')
            ->assertOk()
            ->assertJsonPath('message', 'Properties retrieved successfully')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.owner_email', 'owner@example.com')
            ->assertJsonPath('data.0.today_expenses', 0)
            ->assertJsonMissing(['property_name' => 'Other User House']);
    }

    public function test_authenticated_user_can_delete_their_property_card(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = $user->createToken('auth-token')->plainTextToken;

        $imagePath = 'property_images/test-property.png';
        Storage::disk('public')->put($imagePath, 'fake-image-content');

        $card = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'owner_email' => $user->email,
            'property_image' => $imagePath,
            'property_name' => 'Beach Villa',
            'category' => 'Residential',
            'property_location' => 'Karachi',
            'target_budget' => 5000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'progress' => 0,
        ]);

        $response = $this->withToken($token)
            ->deleteJson("/api/properties/{$card->card_id}");

        $response->assertOk()
            ->assertExactJson([
                'message' => 'Property card deleted successfully',
            ]);

        $this->assertDatabaseMissing('property_cards', [
            'id' => $card->id,
        ]);

        Storage::disk('public')->assertMissing($imagePath);
    }

    public function test_user_cannot_delete_another_users_property_card(): void
    {
        $userOne = User::factory()->create();
        $userTwo = User::factory()->create();
        $tokenOne = $userOne->createToken('auth-token')->plainTextToken;

        $cardTwo = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $userTwo->id,
            'owner_email' => $userTwo->email,
            'property_name' => 'User Two Villa',
            'category' => 'Commercial',
            'property_location' => 'Islamabad',
            'target_budget' => 3000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'progress' => 0,
        ]);

        $response = $this->withToken($tokenOne)
            ->deleteJson("/api/properties/{$cardTwo->card_id}");

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Property card not found',
            ]);

        $this->assertDatabaseHas('property_cards', [
            'id' => $cardTwo->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_delete_property(): void
    {
        $this->deleteJson('/api/properties/some-uuid')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_update_their_property_card(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = $user->createToken('auth-token')->plainTextToken;

        $card = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'owner_email' => $user->email,
            'property_name' => 'Old House Name',
            'category' => 'Residential',
            'property_location' => 'Lahore',
            'target_budget' => 2000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'progress' => 0,
        ]);

        $response = $this->withToken($token)
            ->putJson("/api/properties/{$card->card_id}", [
                'property_name' => 'New Modern Villa',
                'target_budget' => 2500000,
                'property_location' => 'Islamabad',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Property card updated successfully')
            ->assertJsonPath('data.property_name', 'New Modern Villa')
            ->assertJsonPath('data.target_budget', 2500000)
            ->assertJsonPath('data.property_location', 'Islamabad');

        $this->assertDatabaseHas('property_cards', [
            'id' => $card->id,
            'property_name' => 'New Modern Villa',
            'property_location' => 'Islamabad',
            'target_budget' => 2500000,
        ]);
    }

    public function test_user_cannot_update_another_users_property_card(): void
    {
        $userOne = User::factory()->create();
        $userTwo = User::factory()->create();
        $tokenOne = $userOne->createToken('auth-token')->plainTextToken;

        $cardTwo = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $userTwo->id,
            'owner_email' => $userTwo->email,
            'property_name' => 'User Two Property',
            'category' => 'Commercial',
            'property_location' => 'Peshawar',
            'target_budget' => 4000000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'progress' => 0,
        ]);

        $response = $this->withToken($tokenOne)
            ->patchJson("/api/properties/{$cardTwo->card_id}", [
                'property_name' => 'Hacked Name',
            ]);

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Property card not found',
            ]);

        $this->assertDatabaseHas('property_cards', [
            'id' => $cardTwo->id,
            'property_name' => 'User Two Property',
        ]);
    }
}
