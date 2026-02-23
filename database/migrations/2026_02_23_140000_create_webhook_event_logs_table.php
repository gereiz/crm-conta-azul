<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_event_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->nullable();
            $table->string('event_type', 30)->nullable(); // messages.post, messages.put, status, incoming
            $table->boolean('from_me')->default(false);
            $table->string('phone')->nullable(); // número principal utilizado para match
            $table->string('chat_id')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedBigInteger('matched_log_id')->nullable();
            $table->string('reason')->nullable(); // sucesso, sem_match, secret_invalido, etc.
            $table->json('payload_json')->nullable();
            $table->timestamps();
            $table->index(['phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_event_logs');
    }
};
