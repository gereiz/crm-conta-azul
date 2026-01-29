<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_cron_logs', function (Blueprint $table) {
            $table->text('content')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('message_cron_logs', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }
};
