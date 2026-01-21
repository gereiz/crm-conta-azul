<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->boolean('limit_link_preview')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->dropColumn('limit_link_preview');
        });
    }
};
