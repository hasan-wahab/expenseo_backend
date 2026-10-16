<?php

namespace App\Game\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRoom extends Model
{
    protected $table = 'game_rooms';

    protected $fillable = [
        'code',
        'mode',
        'status',
        'player_x_id',
        'player_x_name',
        'player_o_id',
        'player_o_name',
        'board',
        'current_turn',
        'winner',
        'winning_line',
        'player_x_score',
        'player_o_score',
        'player_left',
        'player_x_rematch',
        'player_o_rematch',
        'turn_expires_at',
    ];

    protected $casts = [
        'board' => 'array',
        'winning_line' => 'array',
        'player_x_score' => 'integer',
        'player_o_score' => 'integer',
        'player_x_rematch' => 'boolean',
        'player_o_rematch' => 'boolean',
        'turn_expires_at' => 'datetime',
    ];

    public function playerX(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_x_id');
    }

    public function playerO(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_o_id');
    }

    /**
     * Check if a player is part of this room.
     */
    public function hasPlayer(?int $userId, string $playerName): bool
    {
        if ($userId) {
            return $this->player_x_id === $userId || $this->player_o_id === $userId;
        }

        return $this->player_x_name === $playerName || $this->player_o_name === $playerName;
    }

    /**
     * Get the symbol ('X' or 'O') of a player in this room.
     */
    public function getPlayerSymbol(?int $userId, string $playerName): ?string
    {
        if ($userId) {
            if ($this->player_x_id === $userId) return 'X';
            if ($this->player_o_id === $userId) return 'O';
        }

        if ($this->player_x_name === $playerName) return 'X';
        if ($this->player_o_name === $playerName) return 'O';

        return null;
    }

    /**
     * Format game room state for API & WebSocket broadcast.
     */
    public function toGameStateArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'mode' => $this->mode,
            'status' => $this->status,
            'playerX' => [
                'id' => $this->player_x_id,
                'name' => $this->player_x_name,
                'score' => $this->player_x_score,
            ],
            'playerO' => [
                'id' => $this->player_o_id,
                'name' => $this->player_o_name,
                'score' => $this->player_o_score,
            ],
            'board' => $this->board ?? array_fill(0, 9, ''),
            'currentTurn' => $this->current_turn,
            'winner' => $this->winner,
            'winningLine' => $this->winning_line,
            'playerLeft' => $this->player_left,
            'rematch' => [
                'playerX' => (bool) $this->player_x_rematch,
                'playerO' => (bool) $this->player_o_rematch,
            ],
            'turnExpiresAt' => $this->turn_expires_at?->toISOString(),
            'secondsRemaining' => $this->turn_expires_at ? max(0, now()->diffInSeconds($this->turn_expires_at, false)) : 10,
        ];
    }
}

