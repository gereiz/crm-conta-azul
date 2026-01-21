<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->boolean('disable_link_preview')->default(false)->after('limit_link_preview');
        });
    }

    public function down(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->dropColumn('disable_link_preview');
        });
    }
};
