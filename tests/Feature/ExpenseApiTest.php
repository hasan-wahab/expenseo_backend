<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PropertyCard;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
{
    public function test_unauthenticated_user_cannot_access_expenses(): void
    {
        $this->getJson('/api/expenses')->assertUnauthorized();
        $this->postJson('/api/expenses', [])->assertUnauthorized();
    }

    public function test_user_can_create_expense_and_sync_property_totals(): void
    {
        $user = User::factory()->create(['email' => 'ali@example.com']);
        $token = $user->createToken('auth-token')->plainTextToken;

        $property = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'owner_email' => $user->email,
            'property_name' => 'Gulberg Heights',
            'category' => 'Commercial',
            'property_location' => 'Lahore',
            'target_budget' => 50000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'progress' => 0,
        ]);

        $payload = [
            'card_id' => $property->card_id,
            'property_name' => 'Gulberg Heights',
            'user_email' => 'ali@example.com',
            'amount' => 12500,
            'category' => 'Construction Materials',
            'date' => '2026-10-06',
            'note' => 'Cement and bricks payment',
        ];

        $response = $this->withToken($token)
            ->postJson('/api/expenses', $payload);

        $response->assertCreated()
            ->assertJsonPath('message', 'Expense created successfully')
            ->assertJsonPath('data.amount', 12500)
            ->assertJsonPath('data.property_name', 'Gulberg Heights')
            ->assertJsonPath('data.category', 'Construction Materials');

        $this->assertDatabaseHas('expenses', [
            'card_id' => $property->card_id,
            'user_id' => $user->id,
            'amount' => 12500,
            'category' => 'Construction Materials',
        ]);

        // Property Card ke totals automatically sync hone chahiye
        $property->refresh();
        $this->assertEquals(12500, (float) $property->total_expense);
        $this->assertEquals(12500, (float) $property->today_expenses);
        $this->assertEquals(25.0, (float) $property->progress);
    }

    public function test_user_can_create_expense_with_base64_receipt(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['email' => 'ali@example.com']);
        $token = $user->createToken('auth-token')->plainTextToken;

        $property = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'owner_email' => $user->email,
            'property_name' => 'Home Renovation',
            'category' => 'Residential',
            'property_location' => 'Islamabad',
            'target_budget' => 100000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'progress' => 0,
        ]);

        $receiptBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

        $response = $this->withToken($token)
            ->postJson('/api/expenses', [
                'card_id' => $property->card_id,
                'amount' => 5000,
                'category' => 'Plumbing',
                'date' => '2026-10-06',
                'receipt_image' => $receiptBase64,
            ]);

        $response->assertCreated();

        $expense = Expense::first();
        $this->assertNotNull($expense->receipt_image);
        Storage::disk('public')->assertExists($expense->receipt_image);
    }

    public function test_user_cannot_create_expense_for_another_users_property(): void
    {
        $userOne = User::factory()->create();
        $userTwo = User::factory()->create();

        $tokenOne = $userOne->createToken('auth-token')->plainTextToken;

        $propertyTwo = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $userTwo->id,
            'owner_email' => $userTwo->email,
            'property_name' => 'User Two Property',
            'category' => 'Residential',
            'property_location' => 'Karachi',
            'target_budget' => 100000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'progress' => 0,
        ]);

        $response = $this->withToken($tokenOne)
            ->postJson('/api/expenses', [
                'card_id' => $propertyTwo->card_id,
                'amount' => 1000,
                'category' => 'Paint',
                'date' => '2026-10-06',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('card_id');
    }

    public function test_user_can_list_and_filter_expenses(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth-token')->plainTextToken;

        $propertyOne = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'owner_email' => $user->email,
            'property_name' => 'Property 1',
            'category' => 'Residential',
            'property_location' => 'Lahore',
            'target_budget' => 100000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'today_expenses' => 0,
            'progress' => 0,
        ]);

        $propertyTwo = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'owner_email' => $user->email,
            'property_name' => 'Property 2',
            'category' => 'Residential',
            'property_location' => 'Karachi',
            'target_budget' => 100000,
            'total_expense' => 0,
            'monthly_expense' => 0,
            'today_expenses' => 0,
            'progress' => 0,
        ]);

        $cardOne = $propertyOne->card_id;
        $cardTwo = $propertyTwo->card_id;

        Expense::create([
            'expense_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'card_id' => $cardOne,
            'property_name' => 'Property 1',
            'user_email' => $user->email,
            'amount' => 200,
            'category' => 'Bills',
            'date' => '2026-10-01',
        ]);

        Expense::create([
            'expense_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'card_id' => $cardTwo,
            'property_name' => 'Property 2',
            'user_email' => $user->email,
            'amount' => 300,
            'category' => 'Repair',
            'date' => '2026-10-02',
        ]);

        // Filter by card_id
        $response = $this->withToken($token)
            ->getJson("/api/expenses?card_id={$cardOne}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.card_id', $cardOne);
    }

    public function test_user_can_delete_expense(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $token = $user->createToken('auth-token')->plainTextToken;

        $property = PropertyCard::create([
            'card_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'owner_email' => $user->email,
            'property_name' => 'Villa',
            'category' => 'Residential',
            'property_location' => 'Lahore',
            'target_budget' => 50000,
            'total_expense' => 5000,
            'monthly_expense' => 5000,
            'progress' => 10,
        ]);

        $imagePath = 'receipt_images/receipt123.png';
        Storage::disk('public')->put($imagePath, 'fake-receipt');

        $expense = Expense::create([
            'expense_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'card_id' => $property->card_id,
            'property_name' => 'Villa',
            'user_email' => $user->email,
            'amount' => 5000,
            'category' => 'Woodwork',
            'date' => '2026-10-06',
            'receipt_image' => $imagePath,
        ]);

        $response = $this->withToken($token)
            ->deleteJson("/api/expenses/{$expense->expense_id}");

        $response->assertOk()
            ->assertExactJson([
                'message' => 'Expense deleted successfully',
            ]);

        $this->assertDatabaseMissing('expenses', [
            'id' => $expense->id,
        ]);

        Storage::disk('public')->assertMissing($imagePath);

        $property->refresh();
        $this->assertEquals(0, (float) $property->total_expense);
    }
}
