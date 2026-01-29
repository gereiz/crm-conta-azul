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
        Schema::create('message_crons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('message_template_id'); // Usando unsignedBigInteger ao invés de constrained para evitar erro se a tabela templates não seguir convenção exata, mas vou assumir que existe.
            // Verificando rotas: Route::resource('templates', WhatsappTemplateController::class);
            // Provavelmente a tabela é 'whatsapp_templates' ou 'message_templates'.
            // Vou verificar models.

            $table->string('type'); // billing, due_date, boleto, birthday

            $table->integer('period_value')->nullable();
            $table->string('period_unit')->nullable(); // days, months, years

            $table->integer('days_before_due')->nullable();
            $table->integer('days_after_due')->nullable();

            $table->string('send_time'); // HH:mm
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_crons');
    }
};
