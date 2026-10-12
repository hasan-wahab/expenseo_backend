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
        // Expenses table banayi ja rahi hai jisme tamam users ke expenses store honge.
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('expense_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('card_id')->index();
            $table->string('property_name');
            $table->string('user_email');
            $table->decimal('amount', 65, 2);
            $table->string('category', 100);
            $table->date('date');
            $table->string('receipt_image')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // Queries ki performance behtar karne ke liye indexes
            $table->index(['user_id', 'date']);
            $table->index(['card_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
