<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conta_azul_connections', function (Blueprint $table) {
            $table->id();
            $table->string('empresa_nome');
            $table->string('email_desenvolvedor')->nullable();
            $table->string('ca_client_id');
            $table->text('ca_client_secret');
            $table->string('ca_redirect_uri');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conta_azul_connections');
    }
};
