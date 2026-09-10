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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('refund_id', 100)->nullable()->after('raw_response');
            $table->decimal('refund_amount', 12, 2)->nullable()->after('refund_id');
            $table->string('refund_reason', 255)->nullable()->after('refund_amount');
            $table->timestamp('refunded_at')->nullable()->after('refund_reason');
            $table->json('refund_raw_response')->nullable()->after('refunded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'refund_id',
                'refund_amount',
                'refund_reason',
                'refunded_at',
                'refund_raw_response',
            ]);
        });
    }
};
