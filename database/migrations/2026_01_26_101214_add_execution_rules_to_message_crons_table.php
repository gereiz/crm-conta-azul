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
        Schema::table('message_crons', function (Blueprint $table) {
            $table->string('rule_type')->default('daily')->after('type')->comment('daily, monthly_day, weekly_day, interval_days');
            $table->integer('day_of_month')->nullable()->after('rule_type');
            $table->integer('day_of_week')->nullable()->after('day_of_month');
            $table->integer('interval_days')->nullable()->after('day_of_week');
            $table->boolean('exclude_weekends')->default(false)->after('interval_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_crons', function (Blueprint $table) {
            $table->dropColumn(['rule_type', 'day_of_month', 'day_of_week', 'interval_days', 'exclude_weekends']);
        });
    }
};
