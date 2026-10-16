<?php

namespace App\Game\Events;

use App\Game\Models\GameRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GameRoom $room,
        public ?string $winner,
        public ?array $winningLine
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
        return 'GameEnded';
    }

    public function broadcastWith(): array
    {
        return [
            'game' => $this->room->toGameStateArray(),
            'winner' => $this->winner,
            'winningLine' => $this->winningLine,
        ];
    }
}

