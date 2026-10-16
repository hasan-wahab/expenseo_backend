<?php

namespace App\Game\Services;

use App\Game\Events\MatchFound;
use App\Game\Models\GameMatchmakingQueue;
use App\Game\Models\GameRoom;
use App\Game\Support\GameLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MatchmakingService
{
    /**
     * Enter or pair within the matchmaking queue.
     */
    public function findMatch(int $userId, string $playerName, ?string $socketId = null): array
    {
        return DB::transaction(function () use ($userId, $playerName, $socketId) {
            // 1. Check if this player was already paired into an active quick room just now
            $alreadyMatchedRoom = GameRoom::where('status', 'playing')
                ->where('mode', 'quick')
                ->where(function ($q) use ($userId) {
                    $q->where('player_x_id', $userId)
                      ->orWhere('player_o_id', $userId);
                })
                ->where('created_at', '>=', now()->subSeconds(30))
                ->latest()
                ->first();

            if ($alreadyMatchedRoom) {
                // Remove from queue if still lingering
                GameMatchmakingQueue::where('user_id', $userId)->delete();
                $symbol = $alreadyMatchedRoom->player_x_id === $userId ? 'X' : 'O';

                GameLogger::info('BACKEND-MATCH', "🎯 Returning existing match for '$playerName' (ID: $userId) -> Room: {$alreadyMatchedRoom->code} as $symbol");

                return [
                    'status' => 'matched',
                    'room' => $alreadyMatchedRoom,
                    'assignedSymbol' => $symbol,
                ];
            }

            // 2. Find an eligible opponent waiting in queue
            $opponentEntry = GameMatchmakingQueue::where('user_id', '!=', $userId)
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if ($opponentEntry) {
                // Remove opponent from queue
                $opponentUserId = $opponentEntry->user_id;
                $opponentName = $opponentEntry->player_name;
                $opponentEntry->delete();

                // Also make sure current user is not in queue
                GameMatchmakingQueue::where('user_id', $userId)->delete();

                // Create a matched quick room
                $roomCode = $this->generateUniqueCode();

                $room = GameRoom::create([
                    'code' => $roomCode,
                    'mode' => 'quick',
                    'status' => 'playing',
                    'player_x_id' => $opponentUserId,
                    'player_x_name' => $opponentName,
                    'player_o_id' => $userId,
                    'player_o_name' => $playerName,
                    'board' => array_fill(0, 9, ''),
                    'current_turn' => 'X',
                    'player_x_score' => 0,
                    'player_o_score' => 0,
                    'turn_expires_at' => now()->addSeconds(GameEngine::TURN_SECONDS),
                ]);

                GameLogger::info('BACKEND-MATCH', "🚀 PAIR CREATED! '$opponentName' (Player X) vs '$playerName' (Player O) -> Room: $roomCode");

                // Notify both players through their private WebSocket channels
                event(new MatchFound($opponentUserId, $room, 'X'));
                event(new MatchFound($userId, $room, 'O'));

                return [
                    'status' => 'matched',
                    'room' => $room,
                    'assignedSymbol' => 'O',
                ];
            }

            // No opponent yet, put user in queue
            GameMatchmakingQueue::updateOrCreate(
                ['user_id' => $userId],
                [
                    'player_name' => $playerName,
                    'socket_id' => $socketId,
                    'created_at' => now(),
                ]
            );

            GameLogger::info('BACKEND-MATCH', "⏳ In Queue: '$playerName' (ID: $userId) is waiting for opponent...");

            return [
                'status' => 'waiting',
                'message' => 'Searching for a worthy opponent...',
            ];
        });
    }

    /**
     * Remove player from queue when cancelled or disconnected.
     */
    public function cancelMatchmaking(int $userId): bool
    {
        GameLogger::info('BACKEND-MATCH', "🛑 User ID $userId cancelled matchmaking queue.");
        return (bool) GameMatchmakingQueue::where('user_id', $userId)->delete();
    }

    /**
     * Generate a collision-free short uppercase room code.
     */
    public function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (GameRoom::where('code', $code)->exists());

        return $code;
    }
}

