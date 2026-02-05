<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('reference_code')->nullable()->after('descricao');
            $table->index(['connection_id', 'reference_code']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['connection_id', 'reference_code']);
            $table->dropColumn('reference_code');
        });
    }
};

