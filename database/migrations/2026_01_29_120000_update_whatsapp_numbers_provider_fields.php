<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_numbers', 'provider')) {
                $table->string('provider', 20)->default('whapi')->after('phone');
            }
            if (! Schema::hasColumn('whatsapp_numbers', 'provider_token')) {
                $table->text('provider_token')->nullable()->after('provider');
            }
            if (! Schema::hasColumn('whatsapp_numbers', 'provider_instance')) {
                $table->string('provider_instance')->nullable()->after('provider_token');
            }
            if (Schema::hasColumn('whatsapp_numbers', 'use_poli')) {
                $table->dropColumn('use_poli');
            }
            if (Schema::hasColumn('whatsapp_numbers', 'poli_key')) {
                $table->dropColumn('poli_key');
            }
            if (Schema::hasColumn('whatsapp_numbers', 'poli_customer')) {
                $table->dropColumn('poli_customer');
            }
            if (Schema::hasColumn('whatsapp_numbers', 'poli_channel')) {
                $table->dropColumn('poli_channel');
            }
            if (Schema::hasColumn('whatsapp_numbers', 'poli_user')) {
                $table->dropColumn('poli_user');
            }
            if (Schema::hasColumn('whatsapp_numbers', 'poli_template')) {
                $table->dropColumn('poli_template');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_numbers', 'provider_instance')) {
                $table->dropColumn('provider_instance');
            }
            if (Schema::hasColumn('whatsapp_numbers', 'provider_token')) {
                $table->dropColumn('provider_token');
            }
            if (Schema::hasColumn('whatsapp_numbers', 'provider')) {
                $table->dropColumn('provider');
            }
            if (! Schema::hasColumn('whatsapp_numbers', 'use_poli')) {
                $table->boolean('use_poli')->default(false)->after('status');
            }
            if (! Schema::hasColumn('whatsapp_numbers', 'poli_key')) {
                $table->string('poli_key')->nullable()->after('use_poli');
            }
            if (! Schema::hasColumn('whatsapp_numbers', 'poli_customer')) {
                $table->string('poli_customer')->nullable()->after('poli_key');
            }
            if (! Schema::hasColumn('whatsapp_numbers', 'poli_channel')) {
                $table->string('poli_channel')->nullable()->after('poli_customer');
            }
            if (! Schema::hasColumn('whatsapp_numbers', 'poli_user')) {
                $table->string('poli_user')->nullable()->after('poli_channel');
            }
            if (! Schema::hasColumn('whatsapp_numbers', 'poli_template')) {
                $table->string('poli_template')->nullable()->after('poli_user');
            }
        });
    }
};

