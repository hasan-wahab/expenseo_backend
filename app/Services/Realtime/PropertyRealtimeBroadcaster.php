<?php

namespace App\Services\Realtime;

use App\Events\Realtime\PropertyRealtimeSynced;
use App\Models\Expense;
use App\Models\PropertyCard;
use App\Models\User;
use App\Support\Realtime\PropertyRealtimeAction;

class PropertyRealtimeBroadcaster
{
    /**
     * @param  list<int>  $extraRecipientIds
     * @param  array<string, mixed>|null  $share
     */
    public function notify(
        PropertyCard $property,
        User $actor,
        string $action,
        ?Expense $expense = null,
        ?array $share = null,
        array $extraRecipientIds = [],
        bool $includeProperty = true,
    ): void {
        event(new PropertyRealtimeSynced(
            action: $action,
            actorUserId: (int) $actor->id,
            cardId: $property->card_id,
            recipientUserIds: $this->recipientIds($property, $extraRecipientIds),
            property: $includeProperty ? $property->realtimePayload() : null,
            expense: $expense?->realtimePayload(),
            share: $share,
        ));
    }

    /**
     * @param  list<int>  $recipientIds
     */
    public function notifyDeleted(PropertyCard $property, User $actor, array $recipientIds): void
    {
        event(new PropertyRealtimeSynced(
            action: PropertyRealtimeAction::PROPERTY_DELETED,
            actorUserId: (int) $actor->id,
            cardId: $property->card_id,
            recipientUserIds: array_values(array_unique(array_map('intval', $recipientIds))),
            property: null,
            expense: null,
            share: null,
        ));
    }

    /**
     * @param  list<int>  $extraRecipientIds
     * @return list<int>
     */
    public function recipientIds(PropertyCard $property, array $extraRecipientIds = []): array
    {
        $ids = $property->shares()
            ->pluck('shared_with_user_id')
            ->push($property->user_id)
            ->merge($extraRecipientIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }
}
