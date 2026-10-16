<?php

namespace App\Game\Events;

use App\Game\Models\GameRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MatchFound implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $targetUserId,
        public GameRoom $room,
        public string $assignedSymbol
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('game.user.' . $this->targetUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MatchFound';
    }

    public function broadcastWith(): array
    {
        return [
            'game' => $this->room->toGameStateArray(),
            'yourSymbol' => $this->assignedSymbol,
            'message' => 'Opponent found! Match is starting...',
        ];
    }
}

