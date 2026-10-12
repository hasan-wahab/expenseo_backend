<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('card_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('property_image');
            $table->string('property_name');
            $table->string('category', 100);
            $table->string('property_location');
            $table->decimal('target_budget', 65, 2);
            $table->decimal('total_expense', 65, 2)->default(0);
            $table->decimal('monthly_expense', 65, 2)->default(0);
            $table->decimal('progress', 5, 1)->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_cards');
    }
};
