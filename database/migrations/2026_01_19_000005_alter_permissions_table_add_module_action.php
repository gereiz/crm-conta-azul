<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                if (! Schema::hasColumn('permissions', 'module')) {
                    $table->string('module')->after('id');
                }
                if (! Schema::hasColumn('permissions', 'action')) {
                    $table->string('action')->after('module');
                }
                if (! Schema::hasColumn('permissions', 'description')) {
                    $table->string('description')->nullable()->after('action');
                }
            });
            // Não aplica índice único aqui para evitar falhas em bases legadas com valores inválidos.
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                if (Schema::hasColumn('permissions', 'module')) {
                    $table->dropColumn('module');
                }
                if (Schema::hasColumn('permissions', 'action')) {
                    $table->dropColumn('action');
                }
                if (Schema::hasColumn('permissions', 'description')) {
                    $table->dropColumn('description');
                }
            });
        }
    }
};
