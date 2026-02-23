<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_message_log_id')->constrained('whatsapp_message_logs')->cascadeOnDelete();
            $table->string('provider', 20)->nullable();
            $table->string('status_original', 50)->nullable();
            $table->string('status_normalizado', 20)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_status_logs');
    }
};
