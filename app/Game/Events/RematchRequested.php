<?php

namespace App\Game\Events;

use App\Game\Models\GameRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RematchRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GameRoom $room,
        public string $requestedBySymbol,
        public string $requestedByName
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
        return 'RematchRequested';
    }

    public function broadcastWith(): array
    {
        return [
            'game' => $this->room->toGameStateArray(),
            'requestedBySymbol' => $this->requestedBySymbol,
            'requestedByName' => $this->requestedByName,
            'message' => "{$this->requestedByName} wants a rematch!",
        ];
    }
}

