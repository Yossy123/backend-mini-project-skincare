<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The address form used to start every new address on a fixed point in central Jakarta
     * (-6.2088, 106.8456) and saved it even when the customer never touched the map.
     * Such a pin is a placeholder, not the customer's location, so it is cleared; the customer
     * sets a real pin the next time they edit the address.
     */
    public function up(): void
    {
        DB::table('addresses')
            ->whereBetween('latitude', [-6.20885, -6.20875])
            ->whereBetween('longitude', [106.84555, 106.84565])
            ->update(['latitude' => null, 'longitude' => null]);
    }

    /**
     * The cleared placeholders cannot be told apart from real pins afterwards, so nothing is restored.
     */
    public function down(): void
    {
        //
    }
};
