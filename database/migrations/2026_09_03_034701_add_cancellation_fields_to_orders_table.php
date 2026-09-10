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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cancellation_reason', 100)->nullable()->after('shipping_address');
            $table->text('cancellation_note')->nullable()->after('cancellation_reason');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('cancellation_note');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->timestamp('stock_restored_at')->nullable()->after('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn([
                'cancellation_reason',
                'cancellation_note',
                'cancelled_by',
                'cancelled_at',
                'stock_restored_at',
            ]);
        });
    }
};
