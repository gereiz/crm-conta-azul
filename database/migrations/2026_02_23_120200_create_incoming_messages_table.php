<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_messages', function (Blueprint $table) {
            $table->id();
            $table->string('numero_origem')->nullable();
            $table->string('numero_destino')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
            $table->index(['provider_message_id']);
            $table->index(['numero_origem', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_messages');
    }
};
