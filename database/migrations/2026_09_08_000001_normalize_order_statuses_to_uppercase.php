<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize legacy lowercase order statuses to the canonical UPPERCASE
     * form used by the order lifecycle services and queries.
     */
    public function up(): void
    {
        DB::statement('UPDATE orders SET status = UPPER(status) WHERE status <> UPPER(status)');
    }

    public function down(): void
    {
        // No-op: restoring legacy casing is not meaningful.
    }
};
