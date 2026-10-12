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
        // Property shares table jisme property owner apne friends ke sath card share karega with permissions
        Schema::create('property_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('card_id')->index();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shared_with_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('shared_with_email');

            // Permissions flags
            $table->boolean('can_add_expense')->default(false);
            $table->boolean('can_view_expense')->default(true);
            $table->boolean('can_update_property')->default(false);
            $table->boolean('can_delete_property')->default(false);

            $table->timestamps();

            // Aik property aik hi friend ke sath aik baar share ho sakti hai
            $table->unique(['card_id', 'shared_with_user_id']);

            // Foreign key to property_cards
            $table->foreign('card_id')->references('card_id')->on('property_cards')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_shares');
    }
};
