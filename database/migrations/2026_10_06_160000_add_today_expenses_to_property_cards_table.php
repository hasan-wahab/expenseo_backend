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
        // Property cards table mein today_expenses ka column shamil kiya ja raha hai
        Schema::table('property_cards', function (Blueprint $table) {
            $table->decimal('today_expenses', 65, 2)->default(0)->after('monthly_expense');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_cards', function (Blueprint $table) {
            $table->dropColumn('today_expenses');
        });
    }
};
