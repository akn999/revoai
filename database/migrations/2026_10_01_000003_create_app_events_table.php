<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->string('event', 64);
            $table->char('payload_hash', 64)->unique();
            $table->json('payload');
            $table->dateTime('event_created_at')->nullable();
            $table->enum('status', ['received', 'processing', 'processed', 'ignored', 'failed'])->default('received');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'event', 'event_created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_events');
    }
};
