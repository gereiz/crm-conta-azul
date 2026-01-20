<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                if (!Schema::hasColumn('permissions', 'code')) {
                    $table->string('code')->nullable()->after('id');
                } else {
                    $table->string('code')->nullable()->change();
                }
            });
            // Popular códigos faltantes
            DB::table('permissions')
                ->whereNull('code')
                ->whereNotNull('module')
                ->whereNotNull('action')
                ->update([
                    'code' => DB::raw("LOWER(CONCAT(TRIM(module), '.', TRIM(action)))")
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasColumn('permissions', 'code')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->string('code')->nullable(false)->change();
            });
        }
    }
};
