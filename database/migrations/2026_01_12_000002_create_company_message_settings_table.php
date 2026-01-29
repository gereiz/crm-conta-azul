<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_message_settings')) {
            Schema::create('company_message_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conta_azul_connection_id')->constrained('conta_azul_connections')->cascadeOnDelete();
                $table->string('message_type');
                $table->boolean('is_enabled')->default(false);
                $table->timestamps();
                $table->unique(['conta_azul_connection_id', 'message_type'], 'cms_conn_type_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_message_settings');
    }
};
