<?php

namespace App\Game\Http\Controllers;

use App\Game\Events\GameEnded;
use App\Game\Events\GameReset;
use App\Game\Events\MovePlaced;
use App\Game\Events\PlayerLeft;
use App\Game\Events\RematchRequested;
use App\Game\Models\GameRoom;
use App\Game\Services\GameEngine;
use App\Http\Controllers\Controller;
use App\Game\Support\GameLogger;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GamePlayController extends Controller
{
    public function __construct(protected GameEngine $engine)
    {
    }

    /**
     * Submit a cell move in an active game.
     */
    public function move(Request $request, string $code): JsonResponse
    {
        $startTime = microtime(true);
        $cellIndex = $request->input('cellIndex') ?? $request->input('cell_index');

        if ($cellIndex === null || !is_numeric($cellIndex) || (int)$cellIndex < 0 || (int)$cellIndex > 8) {
            GameLogger::warning('BACKEND-MOVE', "❌ Invalid cell index received for room '$code'");
            return response()->json([
                'success' => false,
                'message' => 'Cell index must be between 0 and 8.',
            ], 422);
        }

        $cellIndex = (int) $cellIndex;
        $user = $request->user();
        $code = strtoupper(trim($code));

        $room = GameRoom::where('code', $code)->first();

        if (!$room) {
            GameLogger::warning('BACKEND-MOVE', "❌ Move failed: Room '$code' not found");
            return response()->json([
                'success' => false,
                'message' => 'Room not found.',
            ], 404);
        }

        $symbol = $room->getPlayerSymbol($user->id, $user->name);
        if (!$symbol) {
            GameLogger::warning('BACKEND-MOVE', "❌ Move unauthorized: '{$user->name}' is not in room '$code'");
            return response()->json([
                'success' => false,
                'message' => 'You are not a participant in this game.',
            ], 403);
        }

        try {
            $outcome = $this->engine->applyMove(
                $room,
                $cellIndex,
                $symbol
            );

            // Broadcast move to both players
            $bStart = microtime(true);
            event(new MovePlaced($room, $cellIndex, $symbol));
            $bMs = round((microtime(true) - $bStart) * 1000, 1);

            // If game finished, broadcast final outcome
            if ($outcome['status'] === 'won' || $outcome['status'] === 'draw') {
                event(new GameEnded($room, $outcome['winner'], $outcome['winningLine']));
                GameLogger::info('BACKEND-MOVE', "🏆 GAME OVER in Room '$code'! Winner: '{$outcome['winner']}'");
            }

            $totalMs = round((microtime(true) - $startTime) * 1000, 1);
            GameLogger::info('BACKEND-MOVE', "✅ Move Placed: Room '$code' | Cell: $cellIndex | Player: '{$user->name}' ($symbol) | Outcome: {$outcome['status']} [Broadcast: {$bMs}ms, Total: {$totalMs}ms]");

            return response()->json([
                'success' => true,
                'outcome' => $outcome['status'],
                'game' => $room->toGameStateArray(),
            ]);
        } catch (Exception $e) {
            GameLogger::warning('BACKEND-MOVE', "❌ Move Rejected: Room '$code' | Cell: $cellIndex | Player: '{$user->name}' ($symbol) | Reason: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Request or accept a rematch in the active room.
     */
    public function rematch(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $code = strtoupper(trim($code));

        $room = GameRoom::where('code', $code)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found.',
            ], 404);
        }

        $symbol = $room->getPlayerSymbol($user->id, $user->name);
        if (!$symbol) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        // Mark this player's agreement to rematch
        if ($symbol === 'X') {
            $room->player_x_rematch = true;
        } else {
            $room->player_o_rematch = true;
        }
        $room->save();

        // If BOTH players have agreed: reset board and start new round!
        if ($room->player_x_rematch && $room->player_o_rematch) {
            $room = $this->engine->resetForNewRound($room);

            GameLogger::info('BACKEND-REMATCH', "🎉 Rematch accepted by BOTH players in Room '$code'! Broadcasting GameReset...");

            // Broadcast reset event to both players
            event(new GameReset($room));

            return response()->json([
                'success' => true,
                'status' => 'started',
                'message' => 'Both players agreed! Starting new round.',
                'game' => $room->toGameStateArray(),
            ]);
        }

        GameLogger::info('BACKEND-REMATCH', "📩 Rematch requested by '{$user->name}' ($symbol) in Room '$code'. Waiting for opponent...");

        // Only one player agreed so far: broadcast request to opponent
        event(new RematchRequested($room, $symbol, $user->name));

        return response()->json([
            'success' => true,
            'status' => 'waiting',
            'message' => 'Waiting for opponent to accept rematch...',
            'game' => $room->toGameStateArray(),
        ]);
    }

    /**
     * Leave / surrender an active game, or decline rematch.
     */
    public function leave(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $code = strtoupper(trim($code));

        $room = GameRoom::where('code', $code)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found.',
            ], 404);
        }

        $symbol = $room->getPlayerSymbol($user->id, $user->name);
        $playerName = $user->name;

        $room->status = 'abandoned';
        $room->player_left = $playerName;
        $room->player_x_rematch = false;
        $room->player_o_rematch = false;

        // If player left during active match, award win to opponent
        if ($room->winner === null && $symbol !== null) {
            $otherSymbol = ($symbol === 'X') ? 'O' : 'X';
            $room->winner = $otherSymbol;
            if ($otherSymbol === 'X') {
                $room->player_x_score += 1;
            } else {
                $room->player_o_score += 1;
            }
        }

        $room->save();

        GameLogger::info('BACKEND-LEAVE', "🚪 Player '$playerName' ($symbol) left Room '$code'. Status set to abandoned.");

        // Broadcast player left event to room (notifies waiting opponent)
        event(new PlayerLeft($room, $playerName));

        return response()->json([
            'success' => true,
            'message' => 'You left the game.',
            'game' => $room->toGameStateArray(),
        ]);
    }
}

