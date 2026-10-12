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
        Schema::table('property_cards', function (Blueprint $table) {
            $table->decimal('target_budget', 65, 2)->change();
            $table->decimal('total_expense', 65, 2)->default(0)->change();
            $table->decimal('monthly_expense', 65, 2)->default(0)->change();
            $table->decimal('today_expenses', 65, 2)->default(0)->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('amount', 65, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_cards', function (Blueprint $table) {
            $table->decimal('target_budget', 12, 2)->change();
            $table->decimal('total_expense', 12, 2)->default(0)->change();
            $table->decimal('monthly_expense', 12, 2)->default(0)->change();
            $table->decimal('today_expenses', 12, 2)->default(0)->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->change();
        });
    }
};

