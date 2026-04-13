<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->index(
                ['cliente_id', 'message_type', 'status', 'sent_at'],
                'wml_cliente_type_status_sent_at_idx'
            );
            $table->index(
                ['whatsapp_number_id', 'phone_sanitized', 'message_type', 'status', 'sent_at'],
                'wml_number_phone_type_status_sent_at_idx'
            );
            $table->index(
                ['status', 'sent_at'],
                'wml_status_sent_at_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->dropIndex('wml_cliente_type_status_sent_at_idx');
            $table->dropIndex('wml_number_phone_type_status_sent_at_idx');
            $table->dropIndex('wml_status_sent_at_idx');
        });
    }
};
