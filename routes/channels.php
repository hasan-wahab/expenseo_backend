<?php

use App\Models\PropertyCard;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function (User $user, int|string $userId): bool {
    return (int) $user->id === (int) $userId;
}, ['guards' => ['sanctum']]);

Broadcast::channel('property.{cardId}', function (User $user, string $cardId): bool {
    $property = PropertyCard::where('card_id', $cardId)->first();

    return $property?->isAccessibleBy($user) ?? false;
}, ['guards' => ['sanctum']]);

// --- Game Multiplayer Channels ---
Broadcast::channel('game.room.{code}', function (User $user, string $code): bool {
    $room = \App\Game\Models\GameRoom::where('code', $code)->first();

    return $room?->hasPlayer($user->id, $user->name) ?? true;
}, ['guards' => ['sanctum']]);

Broadcast::channel('game.user.{userId}', function (User $user, int|string $userId): bool {
    return (int) $user->id === (int) $userId;
}, ['guards' => ['sanctum']]);

