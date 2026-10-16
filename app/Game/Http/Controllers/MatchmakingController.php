<?php

namespace App\Game\Http\Controllers;

use App\Game\Services\MatchmakingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchmakingController extends Controller
{
    public function __construct(protected MatchmakingService $matchmaking)
    {
    }

    /**
     * Enter quick matchmaking to find an instant opponent.
     */
    public function find(Request $request): JsonResponse
    {
        $request->validate([
            'playerName' => 'required|string|max:50',
            'socketId' => 'nullable|string',
        ]);

        $user = $request->user();
        $result = $this->matchmaking->findMatch(
            $user->id,
            $request->input('playerName'),
            $request->input('socketId')
        );

        if ($result['status'] === 'matched') {
            return response()->json([
                'success' => true,
                'status' => 'matched',
                'assignedSymbol' => $result['assignedSymbol'],
                'room' => $result['room']->toGameStateArray(),
                'message' => 'Match found immediately!',
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => 'waiting',
            'channel' => 'game.user.' . $user->id,
            'message' => $result['message'],
        ]);
    }

    /**
     * Cancel matchmaking search.
     */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();
        $cancelled = $this->matchmaking->cancelMatchmaking($user->id);

        return response()->json([
            'success' => true,
            'cancelled' => $cancelled,
            'message' => 'Matchmaking cancelled.',
        ]);
    }
}

