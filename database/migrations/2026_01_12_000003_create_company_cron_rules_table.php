<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_cron_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_azul_connection_id')->constrained('conta_azul_connections')->cascadeOnDelete();
            $table->string('rule_type'); // monthly_day | weekly_day | interval_days
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=domingo..6=sábado
            $table->unsignedSmallInteger('interval_days')->nullable();
            $table->boolean('exclude_weekends')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_cron_rules');
    }
};

