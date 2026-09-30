<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activity-log.connection'))->create(config('activity-log.table', 'activity_logs'), function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->string('action', 96);
            $table->string('level', 16)->default('info');
            $table->text('message')->nullable();

            $table->string('actor_type', 64)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('subject_type', 128)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->unsignedBigInteger('merchant_id')->nullable();

            $table->json('context')->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->string('url', 2048)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->dateTime('created_at', 6);

            $table->index('created_at');
            $table->index(['channel', 'action', 'created_at']);
            $table->index(['level', 'created_at']);
            $table->index(['merchant_id', 'created_at']);
            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::connection(config('activity-log.connection'))->dropIfExists(config('activity-log.table', 'activity_logs'));
    }
};
