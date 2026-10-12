<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_cards', function (Blueprint $table) {
            $table->string('owner_email')->nullable()->after('user_id');
        });

        DB::table('property_cards')
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(500, function ($propertyCards): void {
                foreach ($propertyCards as $propertyCard) {
                    $ownerEmail = DB::table('users')
                        ->where('id', $propertyCard->user_id)
                        ->value('email');

                    DB::table('property_cards')
                        ->where('id', $propertyCard->id)
                        ->update(['owner_email' => $ownerEmail]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('property_cards', function (Blueprint $table) {
            $table->dropColumn('owner_email');
        });
    }
};
