<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained('merchants', 'merchant_id')->cascadeOnDelete();
            $table->json('settings');
            $table->foreignId('updated_from_event_id')->nullable()->constrained('app_events')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('app_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants', 'merchant_id')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('rated_by')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('app_event_id')->nullable()->constrained('app_events')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_feedback');
        Schema::dropIfExists('merchant_settings');
    }
};
