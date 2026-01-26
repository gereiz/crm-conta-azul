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
        Schema::table('message_crons', function (Blueprint $table) {
            // Alterar colunas para JSON (ou TEXT se o banco não suportar JSON nativo, mas Laravel trata bem)
            // Como estamos alterando tipo, pode ser necessário doctrine/dbal, mas vamos tentar o change().
            // Se falhar, fazemos drop e add (já que os dados são novos).
            
            // Abordagem segura: drop e add com novo tipo
            $table->dropColumn(['day_of_month', 'day_of_week']);
        });

        Schema::table('message_crons', function (Blueprint $table) {
            $table->json('day_of_month')->nullable()->after('rule_type');
            $table->json('day_of_week')->nullable()->after('day_of_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->dropColumn(['day_of_month', 'day_of_week']);
        });

        Schema::table('message_crons', function (Blueprint $table) {
            $table->integer('day_of_month')->nullable()->after('rule_type');
            $table->integer('day_of_week')->nullable()->after('day_of_month');
        });
    }
};
