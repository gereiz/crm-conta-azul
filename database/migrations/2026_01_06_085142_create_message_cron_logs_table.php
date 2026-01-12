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
        Schema::create('message_cron_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_cron_id')->constrained('message_crons')->onDelete('cascade');
            // Como 'clientes' pode ser populada via sync e IDs podem mudar ou ser recriados, deixaremos nullable
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('phone');
            $table->string('status'); // success, error
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_cron_logs');
    }
};
