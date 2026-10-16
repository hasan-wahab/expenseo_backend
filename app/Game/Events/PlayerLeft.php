<?php

namespace App\Game\Events;

use App\Game\Models\GameRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerLeft implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GameRoom $room,
        public string $playerName
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('game.room.' . $this->room->code),
        ];
    }

    public function broadcastAs(): string
    {
        return 'PlayerLeft';
    }

    public function broadcastWith(): array
    {
        return [
            'game' => $this->room->toGameStateArray(),
            'playerLeft' => $this->playerName,
            'message' => "{$this->playerName} left the game.",
        ];
    }
}

