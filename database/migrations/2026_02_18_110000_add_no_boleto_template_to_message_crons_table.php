<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            if (!Schema::hasColumn('message_crons', 'send_without_boleto')) {
                $table->boolean('send_without_boleto')->default(false)->after('disable_link_preview');
            }
            if (!Schema::hasColumn('message_crons', 'no_boleto_template_id')) {
                $table->foreignId('no_boleto_template_id')->nullable()->after('send_without_boleto')->constrained('whatsapp_templates')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            if (Schema::hasColumn('message_crons', 'no_boleto_template_id')) {
                $table->dropForeign(['no_boleto_template_id']);
                $table->dropColumn('no_boleto_template_id');
            }
            if (Schema::hasColumn('message_crons', 'send_without_boleto')) {
                $table->dropColumn('send_without_boleto');
            }
        });
    }
};
