<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delayed_cron_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_cron_id')->constrained('message_crons')->cascadeOnDelete();
            $table->timestamp('expected_run_at')->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delayed_cron_queue');
    }
};
