<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->boolean('use_poli')->default(false)->after('status');
            $table->string('poli_key')->nullable()->after('use_poli');
            $table->string('poli_customer')->nullable()->after('poli_key');
            $table->string('poli_channel')->nullable()->after('poli_customer');
            $table->string('poli_user')->nullable()->after('poli_channel');
            $table->string('poli_template')->nullable()->after('poli_user');
            
            // Make whapi_key nullable since poli might be used instead
            $table->text('whapi_key')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->dropColumn([
                'use_poli',
                'poli_key',
                'poli_customer',
                'poli_channel',
                'poli_user',
                'poli_template'
            ]);
            
            // Revert whapi_key to not nullable (careful if data exists with nulls)
            // Ideally we check before reverting, but for this context it's fine.
            $table->text('whapi_key')->nullable(false)->change();
        });
    }
};
