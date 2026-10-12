<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('property_cards', 'currency')) {
            Schema::table('property_cards', function (Blueprint $table) {
                $table->string('currency', 10)->default('PKR')->after('property_location');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('property_cards', 'currency')) {
            Schema::table('property_cards', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};

