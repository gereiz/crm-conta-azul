<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_cron_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('message_cron_logs', 'invoice_count')) {
                $table->unsignedInteger('invoice_count')->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('message_cron_logs', function (Blueprint $table) {
            if (Schema::hasColumn('message_cron_logs', 'invoice_count')) {
                $table->dropColumn('invoice_count');
            }
        });
    }
};
