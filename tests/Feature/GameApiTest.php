<?php

namespace Tests\Feature;

use App\Game\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GameApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_authentication_returns_valid_token(): void
    {
        $response = $this->postJson('/api/game/guest-auth', [
            'playerName' => 'CyberHero',
            'deviceId' => 'test_device_123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => [
                    'name' => 'CyberHero',
                ],
            ]);

        $this->assertNotNull($response->json('token'));
    }

    public function test_create_and_join_private_room(): void
    {
        $host = User::factory()->create(['name' => 'PlayerX']);
        $guest = User::factory()->create(['name' => 'PlayerO']);

        // 1. Host creates room
        $createRes = $this->actingAs($host, 'sanctum')->postJson('/api/game/rooms/create', [
            'playerName' => 'PlayerX',
        ]);

        $createRes->assertStatus(201)
            ->assertJson([
                'success' => true,
                'assignedSymbol' => 'X',
                'room' => [
                    'status' => 'waiting',
                    'playerX' => ['name' => 'PlayerX'],
                ],
            ]);

        $roomCode = $createRes->json('room.code');
        $this->assertNotEmpty($roomCode);

        // 2. Guest joins room
        $joinRes = $this->actingAs($guest, 'sanctum')->postJson('/api/game/rooms/join', [
            'code' => $roomCode,
            'playerName' => 'PlayerO',
        ]);

        $joinRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'assignedSymbol' => 'O',
                'room' => [
                    'status' => 'playing',
                    'playerX' => ['name' => 'PlayerX'],
                    'playerO' => ['name' => 'PlayerO'],
                ],
            ]);
    }

    public function test_players_can_make_moves_and_determine_winner(): void
    {
        $host = User::factory()->create(['name' => 'Host']);
        $guest = User::factory()->create(['name' => 'Guest']);

        $room = GameRoom::create([
            'code' => 'TEST01',
            'mode' => 'private',
            'status' => 'playing',
            'player_x_id' => $host->id,
            'player_x_name' => 'Host',
            'player_o_id' => $guest->id,
            'player_o_name' => 'Guest',
            'board' => array_fill(0, 9, ''),
            'current_turn' => 'X',
        ]);

        // Host moves X to 0 (top-left)
        $move1 = $this->actingAs($host, 'sanctum')->postJson('/api/game/rooms/TEST01/move', [
            'cellIndex' => 0,
        ]);
        $move1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'outcome' => 'continue',
                'game' => [
                    'currentTurn' => 'O',
                    'board' => ['X', '', '', '', '', '', '', '', ''],
                ],
            ]);

        // Guest moves O to 3
        $move2 = $this->actingAs($guest, 'sanctum')->postJson('/api/game/rooms/TEST01/move', [
            'cellIndex' => 3,
        ]);
        $move2->assertStatus(200);

        // Host moves X to 1
        $this->actingAs($host, 'sanctum')->postJson('/api/game/rooms/TEST01/move', ['cellIndex' => 1]);

        // Guest moves O to 4
        $this->actingAs($guest, 'sanctum')->postJson('/api/game/rooms/TEST01/move', ['cellIndex' => 4]);

        // Host moves X to 2 -> Winning row 0,1,2!
        $winMove = $this->actingAs($host, 'sanctum')->postJson('/api/game/rooms/TEST01/move', ['cellIndex' => 2]);
        $winMove->assertStatus(200)
            ->assertJson([
                'success' => true,
                'outcome' => 'won',
                'game' => [
                    'winner' => 'X',
                    'winningLine' => [0, 1, 2],
                    'status' => 'finished',
                    'playerX' => ['score' => 1],
                ],
            ]);
    }

    public function test_quick_matchmaking_pairs_two_waiting_players(): void
    {
        $player1 = User::factory()->create(['name' => 'GamerOne']);
        $player2 = User::factory()->create(['name' => 'GamerTwo']);

        // Player 1 enters queue
        $res1 = $this->actingAs($player1, 'sanctum')->postJson('/api/game/matchmaking/find', [
            'playerName' => 'GamerOne',
        ]);
        $res1->assertStatus(200)->assertJson([
            'status' => 'waiting',
        ]);

        // Player 2 enters queue -> Instant match!
        $res2 = $this->actingAs($player2, 'sanctum')->postJson('/api/game/matchmaking/find', [
            'playerName' => 'GamerTwo',
        ]);
        $res2->assertStatus(200)->assertJson([
            'status' => 'matched',
            'assignedSymbol' => 'O',
            'room' => [
                'status' => 'playing',
                'playerX' => ['name' => 'GamerOne'],
                'playerO' => ['name' => 'GamerTwo'],
            ],
        ]);
    }
}

