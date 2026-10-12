<?php

namespace Tests\Feature;

use App\Events\Realtime\PropertyRealtimeSynced;
use App\Models\PropertyCard;
use App\Models\PropertyShare;
use App\Models\User;
use App\Support\Realtime\PropertyRealtimeAction;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PropertyRealtimeApiTest extends TestCase
{
    public function test_authenticated_user_can_fetch_realtime_config(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/realtime/config')
            ->assertOk()
            ->assertJsonPath('data.driver', 'reverb')
            ->assertJsonPath('data.protocol', 'pusher')
            ->assertJsonPath('data.event', 'synced')
            ->assertJsonPath('data.user_channel', 'private-user.'.$user->id)
            ->assertJsonPath('data.auth_endpoint', url('/api/broadcasting/auth'));
    }

    public function test_guest_cannot_fetch_realtime_config(): void
    {
        $this->getJson('/api/realtime/config')->assertUnauthorized();
    }

    public function test_owner_can_authorize_property_channel(): void
    {
        $owner = User::factory()->create();
        $property = $this->makeProperty($owner);

        $this->withToken($owner->createToken('auth-token')->plainTextToken)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-property.'.$property->card_id,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_friend_can_authorize_shared_property_channel(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $property = $this->makeProperty($owner);

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

        $this->withToken($friend->createToken('auth-token')->plainTextToken)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-property.'.$property->card_id,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_stranger_cannot_authorize_property_channel(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $property = $this->makeProperty($owner);

        $this->withToken($stranger->createToken('auth-token')->plainTextToken)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-property.'.$property->card_id,
            ])
            ->assertForbidden();
    }

    public function test_user_can_only_authorize_own_user_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->withToken($user->createToken('auth-token')->plainTextToken)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-user.'.$user->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->withToken($user->createToken('auth-token')->plainTextToken)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-user.'.$other->id,
            ])
            ->assertForbidden();
    }

    public function test_property_update_broadcasts_to_owner_and_friend(): void
    {
        Event::fake([PropertyRealtimeSynced::class]);

        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $token = $owner->createToken('auth-token')->plainTextToken;

        $property = $this->makeProperty($owner);

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

        $this->withToken($token)
            ->putJson("/api/properties/{$property->card_id}", [
                'property_name' => 'Updated Villa',
            ])
            ->assertOk();

        Event::assertDispatched(PropertyRealtimeSynced::class, function (PropertyRealtimeSynced $event) use ($owner, $friend, $property): bool {
            return $event->action === PropertyRealtimeAction::PROPERTY_UPDATED
                && $event->actorUserId === (int) $owner->id
                && $event->cardId === $property->card_id
                && $event->property['property_name'] === 'Updated Villa'
                && in_array((int) $owner->id, $event->recipientUserIds, true)
                && in_array((int) $friend->id, $event->recipientUserIds, true);
        });
    }

    public function test_sharing_property_broadcasts_to_friend(): void
    {
        Event::fake([PropertyRealtimeSynced::class]);

        $owner = User::factory()->create();
        $friend = User::factory()->create(['email' => 'friend@example.com']);
        $token = $owner->createToken('auth-token')->plainTextToken;
        $property = $this->makeProperty($owner);

        $this->withToken($token)
            ->postJson("/api/properties/{$property->card_id}/share", [
                'email' => 'friend@example.com',
                'can_add_expense' => true,
                'can_view_expense' => true,
            ])
            ->assertOk();

        Event::assertDispatched(PropertyRealtimeSynced::class, function (PropertyRealtimeSynced $event) use ($friend, $property): bool {
            return $event->action === PropertyRealtimeAction::PROPERTY_SHARED
                && $event->cardId === $property->card_id
                && in_array((int) $friend->id, $event->recipientUserIds, true)
                && $event->share['shared_with_email'] === 'friend@example.com';
        });
    }

    public function test_expense_create_broadcasts_updated_property_totals(): void
    {
        Event::fake([PropertyRealtimeSynced::class]);

        $owner = User::factory()->create();
        $token = $owner->createToken('auth-token')->plainTextToken;
        $property = $this->makeProperty($owner);

        $this->withToken($token)
            ->postJson('/api/expenses', [
                'card_id' => $property->card_id,
                'amount' => 1500,
                'category' => 'Paint',
                'date' => '2026-10-06',
            ])
            ->assertCreated();

        Event::assertDispatched(PropertyRealtimeSynced::class, function (PropertyRealtimeSynced $event) use ($property): bool {
            return $event->action === PropertyRealtimeAction::EXPENSE_CREATED
                && $event->cardId === $property->card_id
                && (float) $event->property['total_expense'] === 1500.0
                && $event->expense['amount'] === 1500.0;
        });
    }

    public function test_property_delete_broadcasts_to_owner_and_friend(): void
    {
        Event::fake([PropertyRealtimeSynced::class]);

        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $property = $this->makeProperty($owner);

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

        $this->withToken($owner->createToken('auth-token')->plainTextToken)
            ->deleteJson("/api/properties/{$property->card_id}")
            ->assertOk();

        Event::assertDispatched(PropertyRealtimeSynced::class, function (PropertyRealtimeSynced $event) use ($owner, $friend, $property): bool {
            return $event->action === PropertyRealtimeAction::PROPERTY_DELETED
                && $event->cardId === $property->card_id
                && $event->property === null
                && in_array((int) $owner->id, $event->recipientUserIds, true)
                && in_array((int) $friend->id, $event->recipientUserIds, true);
        });
    }

    public function test_revoking_share_broadcasts_to_friend(): void
    {
        Event::fake([PropertyRealtimeSynced::class]);

        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $property = $this->makeProperty($owner);

        $share = PropertyShare::create([
            'card_id' => $property->card_id,
            'owner_id' => $owner->id,
            'shared_with_user_id' => $friend->id,
            'shared_with_email' => $friend->email,
            'can_add_expense' => true,
            'can_view_expense' => true,
            'can_update_property' => false,
            'can_delete_property' => false,
        ]);

        $this->withToken($owner->createToken('auth-token')->plainTextToken)
            ->deleteJson("/api/properties/{$property->card_id}/shares/{$share->id}")
            ->assertOk();

        Event::assertDispatched(PropertyRealtimeSynced::class, function (PropertyRealtimeSynced $event) use ($friend, $property): bool {
            return $event->action === PropertyRealtimeAction::SHARE_REVOKED
                && $event->cardId === $property->card_id
                && in_array((int) $friend->id, $event->recipientUserIds, true);
        });
    }

    private function makeProperty(User $owner): PropertyCard
    {
        return PropertyCard::create([
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
    }
}
