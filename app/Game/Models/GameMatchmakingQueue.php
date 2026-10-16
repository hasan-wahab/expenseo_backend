<?php

namespace App\Game\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameMatchmakingQueue extends Model
{
    public $timestamps = false;
    protected $table = 'game_matchmaking_queues';

    protected $fillable = [
        'user_id',
        'player_name',
        'rating',
        'socket_id',
        'created_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

