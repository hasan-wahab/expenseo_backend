<?php

namespace App\Game\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use App\Game\Support\GameLogger;

class GameAuthController extends Controller
{
    /**
     * Provide instant guest authentication for mobile game players.
     */
    public function guestAuth(Request $request): JsonResponse
    {
        $start = microtime(true);
        $request->validate([
            'playerName' => 'nullable|string|max:50',
            'deviceId' => 'nullable|string|max:100',
        ]);

        $deviceId = $request->input('deviceId') ?: Str::uuid()->toString();
        $playerName = $request->input('playerName') ?: 'NeonPlayer_' . rand(100, 999);

        // Find or create guest user based on device ID
        $email = "guest_{$deviceId}@game.neon";
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $playerName,
                'password' => Hash::make(Str::random(32)),
            ]
        );

        if ($request->filled('playerName') && $user->name !== $playerName) {
            $user->name = $playerName;
            $user->save();
        }

        // Issue Sanctum token
        $token = $user->createToken('game_auth_token')->plainTextToken;
        $ms = round((microtime(true) - $start) * 1000, 1);

        GameLogger::info('BACKEND-AUTH', "🔑 Guest Authenticated: '{$user->name}' (User ID: {$user->id}) [Took {$ms}ms]");

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
        ]);
    }

    /**
     * Get current authenticated user profile for game.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }
}

