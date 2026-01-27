<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->boolean('run_when_delayed')->default(false)->after('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->dropColumn('run_when_delayed');
        });
    }
};
