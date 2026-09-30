<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('domain')->nullable();
            $table->string('avatar')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->enum('store_type', ['development', 'demo', 'live'])->nullable()->index();
            $table->enum('status', ['pending', 'active', 'inactive', 'uninstalled'])->default('pending')->index();
            $table->json('app_scopes')->nullable();
            $table->dateTime('installed_at')->nullable();
            $table->dateTime('uninstalled_at')->nullable();
            $table->dateTime('profile_synced_at')->nullable();
            $table->dateTime('last_event_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
