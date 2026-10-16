<?php

namespace App\Game\Events;

use App\Game\Models\GameRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameReset implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public GameRoom $room)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('game.room.' . $this->room->code),
        ];
    }

    public function broadcastAs(): string
    {
        return 'GameReset';
    }

    public function broadcastWith(): array
    {
        return [
            'game' => $this->room->toGameStateArray(),
            'message' => 'New round started!',
        ];
    }
}

