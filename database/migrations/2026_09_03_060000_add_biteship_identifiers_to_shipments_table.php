<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('biteship_order_id')->nullable()->unique()->after('order_id');
            $table->string('biteship_tracking_id')->nullable()->index()->after('biteship_order_id');
            $table->string('biteship_waybill_id')->nullable()->index()->after('biteship_tracking_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique(['biteship_order_id']);
            $table->dropIndex(['biteship_tracking_id']);
            $table->dropIndex(['biteship_waybill_id']);
            $table->dropColumn([
                'biteship_order_id',
                'biteship_tracking_id',
                'biteship_waybill_id',
            ]);
        });
    }
};
