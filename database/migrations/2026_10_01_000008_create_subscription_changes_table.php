<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->index()->constrained('merchants', 'merchant_id')->cascadeOnDelete();
            $table->enum('change_type', [
                'started', 'renewed', 'canceled', 'expired', 'superseded',
                'plan_changed', 'cycle_changed', 'quantity_changed',
            ]);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('from_billing_cycle', 20)->nullable();
            $table->string('to_billing_cycle', 20)->nullable();
            $table->unsignedBigInteger('from_plan_id')->nullable();
            $table->unsignedBigInteger('to_plan_id')->nullable();
            $table->foreignId('app_event_id')->nullable()->constrained('app_events')->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
    }
};
