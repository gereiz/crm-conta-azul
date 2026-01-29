<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasColumn('permissions', 'name')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->string('name')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // No-op: não força voltar a NOT NULL para não quebrar dados.
    }
};
