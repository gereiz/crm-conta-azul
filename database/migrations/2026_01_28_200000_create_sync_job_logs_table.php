<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_job_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conta_azul_connection_id')->nullable();
            $table->string('job_type')->default('invoices'); // invoices, clients, etc.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('items_processed')->default(0);
            $table->string('status')->default('success'); // success, error
            $table->text('message')->nullable();
            $table->timestamps();

            $table->foreign('conta_azul_connection_id')
                ->references('id')
                ->on('conta_azul_connections')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_job_logs');
    }
};
