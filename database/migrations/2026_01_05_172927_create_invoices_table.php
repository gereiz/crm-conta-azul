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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('ca_id')->unique()->nullable(); // ID da Conta Azul
            $table->string('status')->nullable();
            $table->decimal('valor_original', 15, 2)->nullable(); // Campo 'total' na API
            $table->decimal('saldo_devedor', 15, 2)->nullable(); // Campo 'nao_pago' na API
            $table->text('descricao')->nullable();
            $table->date('data_vencimento')->nullable();
            $table->date('data_emissao')->nullable();
            $table->string('link_boleto')->nullable();
            
            // Relacionamento com cliente local
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->onDelete('cascade');
            // Armazena também o ID do cliente na CA caso o local não exista ainda
            $table->string('cliente_ca_id')->nullable(); 
            $table->string('cliente_nome')->nullable(); // Backup do nome

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
