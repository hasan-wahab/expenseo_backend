<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PropertyRealtimeSynced implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $recipientUserIds
     * @param  array<string, mixed>|null  $property
     * @param  array<string, mixed>|null  $expense
     * @param  array<string, mixed>|null  $share
     */
    public function __construct(
        public readonly string $action,
        public readonly int $actorUserId,
        public readonly string $cardId,
        public readonly array $recipientUserIds,
        public readonly ?array $property = null,
        public readonly ?array $expense = null,
        public readonly ?array $share = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('property.'.$this->cardId),
        ];

        foreach (array_unique($this->recipientUserIds) as $userId) {
            $channels[] = new PrivateChannel('user.'.$userId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'synced';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'actor_user_id' => $this->actorUserId,
            'card_id' => $this->cardId,
            'property' => $this->property,
            'expense' => $this->expense,
            'share' => $this->share,
        ];
    }
}
