<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained('merchants', 'merchant_id')->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->string('token_type', 20)->default('bearer');
            $table->text('scope')->nullable();
            $table->dateTime('expires_at')->index();
            $table->dateTime('refreshed_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_tokens');
    }
};
