<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('system_settings', 'orchestrator_delay_min_seconds')) {
                $table->unsignedInteger('orchestrator_delay_min_seconds')->default(6)->after('secondary_color');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_delay_max_seconds')) {
                $table->unsignedInteger('orchestrator_delay_max_seconds')->default(20)->after('orchestrator_delay_min_seconds');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_batch_size')) {
                $table->unsignedInteger('orchestrator_batch_size')->default(30)->after('orchestrator_delay_max_seconds');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_batch_interval_min_seconds')) {
                $table->unsignedInteger('orchestrator_batch_interval_min_seconds')->default(300)->after('orchestrator_batch_size');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_batch_interval_max_seconds')) {
                $table->unsignedInteger('orchestrator_batch_interval_max_seconds')->default(420)->after('orchestrator_batch_interval_min_seconds');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_hourly_limit_per_number')) {
                $table->unsignedInteger('orchestrator_hourly_limit_per_number')->default(100)->after('orchestrator_batch_interval_max_seconds');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_safe_start_hour')) {
                $table->string('orchestrator_safe_start_hour', 5)->default('08:00')->after('orchestrator_hourly_limit_per_number');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_safe_end_hour')) {
                $table->string('orchestrator_safe_end_hour', 5)->default('20:00')->after('orchestrator_safe_start_hour');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_concurrent_cooldown_minutes')) {
                $table->unsignedInteger('orchestrator_concurrent_cooldown_minutes')->default(15)->after('orchestrator_safe_end_hour');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_pause_on_error_minutes')) {
                $table->unsignedInteger('orchestrator_pause_on_error_minutes')->default(30)->after('orchestrator_concurrent_cooldown_minutes');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_warmup_day1_limit')) {
                $table->unsignedInteger('orchestrator_warmup_day1_limit')->default(20)->after('orchestrator_pause_on_error_minutes');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_warmup_day2_limit')) {
                $table->unsignedInteger('orchestrator_warmup_day2_limit')->default(40)->after('orchestrator_warmup_day1_limit');
            }
            if (! Schema::hasColumn('system_settings', 'orchestrator_warmup_day3_limit')) {
                $table->unsignedInteger('orchestrator_warmup_day3_limit')->default(60)->after('orchestrator_warmup_day2_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            foreach ([
                'orchestrator_delay_min_seconds',
                'orchestrator_delay_max_seconds',
                'orchestrator_batch_size',
                'orchestrator_batch_interval_min_seconds',
                'orchestrator_batch_interval_max_seconds',
                'orchestrator_hourly_limit_per_number',
                'orchestrator_safe_start_hour',
                'orchestrator_safe_end_hour',
                'orchestrator_concurrent_cooldown_minutes',
                'orchestrator_pause_on_error_minutes',
                'orchestrator_warmup_day1_limit',
                'orchestrator_warmup_day2_limit',
                'orchestrator_warmup_day3_limit',
            ] as $col) {
                if (Schema::hasColumn('system_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
