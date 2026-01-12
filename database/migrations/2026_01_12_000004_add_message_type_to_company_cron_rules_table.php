<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_cron_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('company_cron_rules', 'message_type')) {
                $table->string('message_type')->default('billing')->after('conta_azul_connection_id');
            }
        });

        // Garantir que há um valor para linhas existentes
        DB::table('company_cron_rules')->whereNull('message_type')->update(['message_type' => 'billing']);

        // Índice único por empresa + tipo de mensagem
        Schema::table('company_cron_rules', function (Blueprint $table) {
            $table->unique(['conta_azul_connection_id', 'message_type'], 'company_cron_rules_connection_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('company_cron_rules', function (Blueprint $table) {
            if (Schema::hasColumn('company_cron_rules', 'message_type')) {
                $table->dropUnique('company_cron_rules_connection_type_unique');
                $table->dropColumn('message_type');
            }
        });
    }
};
