<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('conta_azul_connections')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('type');
            $table->string('value');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['connection_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_restrictions');
    }
};
