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
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('label')->nullable()->after('user_id');
            $table->string('recipient_name')->nullable()->after('label');
            $table->text('address_line')->nullable()->after('address');
            $table->text('address_detail')->nullable()->after('address_line');
            $table->decimal('latitude', 10, 7)->nullable()->after('postal_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('google_place_id')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn([
                'label',
                'recipient_name',
                'address_line',
                'address_detail',
                'latitude',
                'longitude',
                'google_place_id',
            ]);
        });
    }
};
