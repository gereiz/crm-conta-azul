<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_message_logs', 'provider_message_id')) {
                $table->string('provider_message_id')->nullable()->after('provider');
                $table->index('provider_message_id');
            }
            if (! Schema::hasColumn('whatsapp_message_logs', 'delivery_status')) {
                $table->string('delivery_status', 20)->nullable()->after('status');
                $table->timestamp('delivery_status_updated_at')->nullable()->after('delivery_status');
                $table->index(['delivery_status', 'created_at']);
            }
            if (! Schema::hasColumn('whatsapp_message_logs', 'responded')) {
                $table->boolean('responded')->default(false)->after('delivery_status_updated_at');
                $table->timestamp('responded_at')->nullable()->after('responded');
                $table->index('responded');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_message_logs', 'provider_message_id')) {
                $table->dropIndex(['provider_message_id']);
                $table->dropColumn('provider_message_id');
            }
            if (Schema::hasColumn('whatsapp_message_logs', 'delivery_status')) {
                $table->dropIndex(['delivery_status', 'created_at']);
                $table->dropColumn('delivery_status');
                $table->dropColumn('delivery_status_updated_at');
            }
            if (Schema::hasColumn('whatsapp_message_logs', 'responded')) {
                $table->dropIndex(['responded']);
                $table->dropColumn('responded');
                $table->dropColumn('responded_at');
            }
        });
    }
};
