<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('merchant_order_id', 100)->nullable()->unique();
            $table->boolean('requires_review')->default(false);
            $table->string('refund_request_key', 100)->nullable();
            $table->decimal('refund_request_amount', 14, 2)->nullable();
            $table->string('refund_request_reason')->nullable();
            $table->json('refund_completed_keys')->nullable();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('weight')->nullable();
        });
        // Only recover identities recorded by the gateway. Never infer them from a local ID.
        DB::table('payments')->orderBy('id')->each(function ($payment) {
            $response = json_decode($payment->raw_response ?? '{}', true);
            $identity = $response['order_id'] ?? null;
            if (is_string($identity) && $identity !== '') {
                DB::table('payments')->where('id', $payment->id)->update(['merchant_order_id' => $identity]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['merchant_order_id', 'requires_review', 'refund_request_key', 'refund_request_amount', 'refund_request_reason', 'refund_completed_keys']));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('weight'));
    }
};
