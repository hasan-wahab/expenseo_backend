<?php

namespace App\Game\Http\Controllers;

use App\Game\Events\PlayerJoinedRoom;
use App\Game\Models\GameRoom;
use App\Game\Services\GameEngine;
use App\Game\Services\MatchmakingService;
use App\Game\Support\GameLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function __construct(protected MatchmakingService $matchmaking)
    {
    }

    /**
     * Create a private game room with a shareable code.
     */
    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'playerName' => 'required|string|max:50',
        ]);

        $user = $request->user();
        $code = $this->matchmaking->generateUniqueCode();

        $room = GameRoom::create([
            'code' => $code,
            'mode' => 'private',
            'status' => 'waiting',
            'player_x_id' => $user->id,
            'player_x_name' => $request->input('playerName'),
            'board' => array_fill(0, 9, ''),
            'current_turn' => 'X',
            'player_x_score' => 0,
            'player_o_score' => 0,
        ]);

        GameLogger::info('BACKEND-ROOM', "🏠 Private Room Created: '$code' by '{$user->name}' (Player X)");

        return response()->json([
            'success' => true,
            'assignedSymbol' => 'X',
            'room' => $room->toGameStateArray(),
        ], 201);
    }

    /**
     * Join an existing private game room using code.
     */
    public function join(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:10',
            'playerName' => 'required|string|max:50',
        ]);

        $user = $request->user();
        $code = strtoupper(trim($request->input('code')));

        $room = GameRoom::where('code', $code)->first();

        if (!$room) {
            GameLogger::warning('BACKEND-ROOM', "❌ Join Failed: Room '$code' not found");
            return response()->json([
                'success' => false,
                'message' => 'Room code not found.',
            ], 404);
        }

        if ($room->status !== 'waiting') {
            GameLogger::warning('BACKEND-ROOM', "❌ Join Failed: Room '$code' status is '{$room->status}' (already started)");
            return response()->json([
                'success' => false,
                'message' => 'This room is already in progress or completed.',
            ], 400);
        }

        if ($room->player_x_id === $user->id && $room->player_x_name === $request->input('playerName')) {
            GameLogger::warning('BACKEND-ROOM', "❌ Join Failed: User '{$user->name}' tried to join their own room '$code'");
            return response()->json([
                'success' => false,
                'message' => 'You cannot join your own room as an opponent.',
            ], 400);
        }

        // Add Player O and start game
        $room->player_o_id = $user->id;
        $room->player_o_name = $request->input('playerName');
        $room->status = 'playing';
        $room->turn_expires_at = now()->addSeconds(GameEngine::TURN_SECONDS);
        $room->save();

        GameLogger::info('BACKEND-ROOM', "🤝 Player '{$user->name}' joined Room '$code' as Player O! Status -> playing. Broadcasting PlayerJoinedRoom...");

        // Broadcast to Host waiting on WebSocket
        event(new PlayerJoinedRoom($room));

        return response()->json([
            'success' => true,
            'assignedSymbol' => 'O',
            'room' => $room->toGameStateArray(),
        ]);
    }

    /**
     * Get room details by code.
     */
    public function show(string $code): JsonResponse
    {
        $room = GameRoom::where('code', strtoupper($code))->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'room' => $room->toGameStateArray(),
        ]);
    }
}

