<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('specialization');
            $table->string('license_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('experience')->nullable();
            $table->decimal('rating', 3, 2)->default(5.00);
            $table->unsignedInteger('review_count')->default(0);
            $table->string('avatar_color')->default('from-rose-500 to-pink-500');
            $table->text('bio')->nullable();
            $table->json('skills')->nullable();
            $table->string('schedule_days')->nullable();
            $table->json('available_days')->nullable();
            $table->time('work_start_time')->default('09:00:00');
            $table->time('work_end_time')->default('17:00:00');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['status', 'specialization']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
