<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_send_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whatsapp_number_id')->unique();
            $table->boolean('in_progress')->default(false);
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->integer('hourly_count')->default(0);
            $table->timestamp('hourly_window_start')->nullable();
            $table->integer('daily_count')->default(0);
            $table->date('daily_date')->nullable();
            $table->date('warmup_start_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_send_states');
    }
};
