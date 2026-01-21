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
        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->nullable()->constrained('conta_azul_connections')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable(); // Não constrained pois cliente pode ser deletado e queremos manter log
            $table->string('client_name')->nullable();
            $table->string('phone_original')->nullable();
            $table->string('phone_sanitized')->nullable();
            $table->string('message_type')->default('manual'); // manual, cron_billing, etc.
            $table->foreignId('message_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->integer('total_boletos')->default(0);
            $table->json('boleto_ids')->nullable();
            $table->string('status'); // success, error, skipped
            $table->text('error_message')->nullable();
            $table->text('content')->nullable(); // Conteúdo da mensagem enviada
            $table->string('batch_id')->nullable(); // Para agrupar envios em massa/cron
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
    }
};
