<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->index(['is_active', 'send_time'], 'message_crons_active_send_time_idx');
            $table->index(['is_active', 'run_when_delayed', 'send_time', 'last_run_at'], 'message_crons_delayed_lookup_idx');
        });

        Schema::table('webhook_event_logs', function (Blueprint $table) {
            $table->index(['matched_log_id', 'created_at'], 'wel_matched_log_created_idx');
            $table->index(['provider_message_id', 'created_at'], 'wel_provider_message_created_idx');
            $table->index(['phone', 'reason', 'created_at'], 'wel_phone_reason_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_event_logs', function (Blueprint $table) {
            $table->dropIndex('wel_matched_log_created_idx');
            $table->dropIndex('wel_provider_message_created_idx');
            $table->dropIndex('wel_phone_reason_created_idx');
        });

        Schema::table('message_crons', function (Blueprint $table) {
            $table->dropIndex('message_crons_active_send_time_idx');
            $table->dropIndex('message_crons_delayed_lookup_idx');
        });
    }
};
