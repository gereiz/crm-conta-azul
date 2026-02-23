<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('system_settings', 'whapi_webhook_enabled')) {
                $table->boolean('whapi_webhook_enabled')->default(false)->after('evolution_api_base_url');
            }
            if (! Schema::hasColumn('system_settings', 'whapi_webhook_secret')) {
                $table->string('whapi_webhook_secret')->nullable()->after('whapi_webhook_enabled');
            }
            if (! Schema::hasColumn('system_settings', 'whapi_webhook_url')) {
                $table->string('whapi_webhook_url')->nullable()->after('whapi_webhook_secret');
            }

            if (! Schema::hasColumn('system_settings', 'evolution_webhook_enabled')) {
                $table->boolean('evolution_webhook_enabled')->default(false)->after('whapi_webhook_url');
            }
            if (! Schema::hasColumn('system_settings', 'evolution_webhook_secret')) {
                $table->string('evolution_webhook_secret')->nullable()->after('evolution_webhook_enabled');
            }
            if (! Schema::hasColumn('system_settings', 'evolution_webhook_url')) {
                $table->string('evolution_webhook_url')->nullable()->after('evolution_webhook_secret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            foreach ([
                'whapi_webhook_enabled',
                'whapi_webhook_secret',
                'whapi_webhook_url',
                'evolution_webhook_enabled',
                'evolution_webhook_secret',
                'evolution_webhook_url',
            ] as $col) {
                if (Schema::hasColumn('system_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
