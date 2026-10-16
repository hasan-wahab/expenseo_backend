<?php

use App\Game\Http\Controllers\GameAuthController;
use App\Game\Http\Controllers\GamePlayController;
use App\Game\Http\Controllers\MatchmakingController;
use App\Game\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Game API Routes (Tic-Tac-Toe Multiplayer)
|--------------------------------------------------------------------------
| All routes here are isolated for the Game module and prefixed with /api/game.
*/

// Guest authentication for quick mobile play
Route::post('/guest-auth', [GameAuthController::class, 'guestAuth']);

// Authenticated Game Routes (Sanctum)
Route::middleware('auth:sanctum')->group(function () {

    // Current player profile
    Route::get('/me', [GameAuthController::class, 'me']);

    // Private Room Management (Code-based)
    Route::prefix('rooms')->group(function () {
        Route::post('/create', [RoomController::class, 'create']);
        Route::post('/join', [RoomController::class, 'join']);
        Route::get('/{code}', [RoomController::class, 'show']);
    });

    // Quick Matchmaking (Random 1-click play)
    Route::prefix('matchmaking')->group(function () {
        Route::post('/find', [MatchmakingController::class, 'find']);
        Route::post('/cancel', [MatchmakingController::class, 'cancel']);
    });

    // In-game actions (Move, Rematch, Leave)
    Route::prefix('rooms/{code}')->group(function () {
        Route::post('/move', [GamePlayController::class, 'move']);
        Route::post('/rematch', [GamePlayController::class, 'rematch']);
        Route::post('/leave', [GamePlayController::class, 'leave']);
    });

});

