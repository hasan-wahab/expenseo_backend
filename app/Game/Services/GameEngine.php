<?php

namespace App\Game\Services;

use App\Game\Models\GameRoom;
use InvalidArgumentException;

class GameEngine
{
    public const TURN_SECONDS = 15;

    public const WINNING_LINES = [
        [0, 1, 2], // Row 1
        [3, 4, 5], // Row 2
        [6, 7, 8], // Row 3
        [0, 3, 6], // Col 1
        [1, 4, 7], // Col 2
        [2, 5, 8], // Col 3
        [0, 4, 8], // Diag 1
        [2, 4, 6], // Diag 2
    ];

    /**
     * Process a player's move on the board.
     * Returns an array with outcome details.
     */
    public function applyMove(GameRoom $room, int $cellIndex, string $playerSymbol): array
    {
        if ($room->status !== 'playing') {
            throw new InvalidArgumentException("Game is not currently active.");
        }

        if ($room->current_turn !== $playerSymbol) {
            throw new InvalidArgumentException("It is not your turn!");
        }

        if ($cellIndex < 0 || $cellIndex > 8) {
            throw new InvalidArgumentException("Invalid cell index.");
        }

        $board = $room->board ?? array_fill(0, 9, '');

        if (!empty($board[$cellIndex])) {
            throw new InvalidArgumentException("This cell is already occupied.");
        }

        // Apply symbol
        $board[$cellIndex] = $playerSymbol;
        $room->board = $board;

        // Check for win
        $winningLine = $this->checkWinningLine($board, $playerSymbol);
        if ($winningLine !== null) {
            $room->winner = $playerSymbol;
            $room->winning_line = $winningLine;
            $room->status = 'finished';
            $room->turn_expires_at = null;

            if ($playerSymbol === 'X') {
                $room->player_x_score += 1;
            } else {
                $room->player_o_score += 1;
            }

            $room->save();

            return [
                'status' => 'won',
                'winner' => $playerSymbol,
                'winningLine' => $winningLine,
                'room' => $room,
            ];
        }

        // Check for draw
        if ($this->checkDraw($board)) {
            $room->winner = 'draw';
            $room->winning_line = null;
            $room->status = 'finished';
            $room->turn_expires_at = null;
            $room->save();

            return [
                'status' => 'draw',
                'winner' => 'draw',
                'winningLine' => null,
                'room' => $room,
            ];
        }

        // Game continues: switch turn
        $room->current_turn = ($playerSymbol === 'X') ? 'O' : 'X';
        $room->turn_expires_at = now()->addSeconds(self::TURN_SECONDS);
        $room->save();

        return [
            'status' => 'continue',
            'winner' => null,
            'winningLine' => null,
            'room' => $room,
        ];
    }

    /**
     * Check if the specified symbol has won, returns the 3 winning indices or null.
     */
    public function checkWinningLine(array $board, string $symbol): ?array
    {
        foreach (self::WINNING_LINES as $line) {
            [$a, $b, $c] = $line;
            if (
                isset($board[$a], $board[$b], $board[$c]) &&
                $board[$a] === $symbol &&
                $board[$b] === $symbol &&
                $board[$c] === $symbol
            ) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Check if the board is completely filled (draw).
     */
    public function checkDraw(array $board): bool
    {
        foreach ($board as $cell) {
            if (empty($cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reset the room board for a new round while preserving scores.
     */
    public function resetForNewRound(GameRoom $room): GameRoom
    {
        $room->board = array_fill(0, 9, '');
        $room->current_turn = 'X';
        $room->winner = null;
        $room->winning_line = null;
        $room->status = 'playing';
        $room->player_left = null;
        $room->player_x_rematch = false;
        $room->player_o_rematch = false;
        $room->turn_expires_at = now()->addSeconds(self::TURN_SECONDS);
        $room->save();

        return $room;
    }
}

