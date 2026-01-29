<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('system_settings', 'evolution_api_base_url')) {
                $table->string('evolution_api_base_url')->nullable()->after('contaazul_cron_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            if (Schema::hasColumn('system_settings', 'evolution_api_base_url')) {
                $table->dropColumn('evolution_api_base_url');
            }
        });
    }
};

