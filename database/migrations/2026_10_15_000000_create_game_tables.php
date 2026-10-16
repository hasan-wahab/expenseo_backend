<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('game_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique()->index();
            $table->enum('mode', ['private', 'quick'])->default('private');
            $table->enum('status', ['waiting', 'playing', 'finished', 'abandoned'])->default('waiting');

            // Player X (Host in private room, or Player 1 in quick match)
            $table->foreignId('player_x_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('player_x_name', 100);

            // Player O (Guest)
            $table->foreignId('player_o_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('player_o_name', 100)->nullable();

            // Game state
            $table->json('board'); // 9 elements: ["", "", ...]
            $table->enum('current_turn', ['X', 'O'])->default('X');
            $table->enum('winner', ['X', 'O', 'draw'])->nullable();
            $table->json('winning_line')->nullable(); // e.g. [0, 1, 2]

            // Scores
            $table->unsignedInteger('player_x_score')->default(0);
            $table->unsignedInteger('player_o_score')->default(0);

            // Resignation or leave tracking
            $table->string('player_left', 100)->nullable();

            // Turn expiry for server-authoritative timer
            $table->timestamp('turn_expires_at')->nullable();

            $table->timestamps();
        });

        Schema::create('game_matchmaking_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('player_name', 100);
            $table->integer('rating')->default(1000);
            $table->string('socket_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_matchmaking_queues');
        Schema::dropIfExists('game_rooms');
    }
};

