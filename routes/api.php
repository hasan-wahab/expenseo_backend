<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PropertyCardController;
use App\Http\Controllers\Api\PropertyShareController;
use App\Http\Controllers\Api\RealtimeConfigController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/realtime/config', [RealtimeConfigController::class, 'show']);
    Route::match(['put', 'patch'], '/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/properties', [PropertyCardController::class, 'store']);
    Route::get('/properties', [PropertyCardController::class, 'index']);
    // Property card ko update karne ka endpoint
    Route::match(['put', 'patch'], '/properties/{cardId}', [PropertyCardController::class, 'update']);
    // Property card ko delete karne ka endpoint
    Route::delete('/properties/{cardId}', [PropertyCardController::class, 'destroy']);

    // Friend ko search karne ka endpoint (email ke zariye)
    Route::get('/users/search', [PropertyShareController::class, 'searchUser']);

    // Property sharing ke endpoints
    Route::post('/properties/{cardId}/share', [PropertyShareController::class, 'share']);
    Route::get('/properties/{cardId}/shares', [PropertyShareController::class, 'index']);
    Route::match(['put', 'patch'], '/properties/{cardId}/shares/{shareId}', [PropertyShareController::class, 'update']);
    Route::delete('/properties/{cardId}/shares/{shareId}', [PropertyShareController::class, 'destroy']);

    // Expenses ke endpoints
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses/{expenseId}', [ExpenseController::class, 'show']);
    Route::match(['put', 'patch'], '/expenses/{expenseId}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expenseId}', [ExpenseController::class, 'destroy']);
});
