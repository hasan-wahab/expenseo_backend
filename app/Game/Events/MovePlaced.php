<?php

namespace App\Game\Events;

use App\Game\Models\GameRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MovePlaced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GameRoom $room,
        public int $cellIndex,
        public string $symbol
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
        return 'MovePlaced';
    }

    public function broadcastWith(): array
    {
        return [
            'game' => $this->room->toGameStateArray(),
            'lastMove' => [
                'cellIndex' => $this->cellIndex,
                'symbol' => $this->symbol,
            ],
        ];
    }
}

