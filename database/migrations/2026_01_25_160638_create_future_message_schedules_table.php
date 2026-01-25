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
        Schema::create('future_message_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('conta_azul_connections')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('message_type'); // billing, boleto (emissao), due_date, birthday
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('event_date');
            $table->date('scheduled_send_date');
            $table->string('status'); // pending, blocked, sent, ignored
            $table->string('block_reason')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'message_type', 'scheduled_send_date'], 'idx_future_schedules_query');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('future_message_schedules');
    }
};
