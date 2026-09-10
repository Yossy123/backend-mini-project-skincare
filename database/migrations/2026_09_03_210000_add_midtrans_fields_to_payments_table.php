<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('snap_token')->nullable()->unique();
            $table->text('redirect_url')->nullable();
            $table->string('payment_type')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['snap_token']);
            $table->dropColumn(['snap_token', 'redirect_url', 'payment_type', 'expires_at']);
        });
    }
};
