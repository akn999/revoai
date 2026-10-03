<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops a half-created table first: MySQL commits CREATE TABLE before the foreign keys are added,
     * so an earlier failed run can leave the table behind without recording the migration.
     */
    public function up(): void
    {
        Schema::dropIfExists('subscriptions');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants', 'merchant_id');
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('salla_subscription_id')->nullable();
            $table->enum('item_type', ['plan', 'addon'])->default('plan');
            $table->string('item_key', 100)->default('plan');
            $table->string('plan_name')->nullable();
            $table->enum('plan_type', ['recurring', 'one_time'])->nullable();
            $table->enum('billing_cycle', ['monthly', 'yearly', 'one_time', 'trial', 'custom']);
            $table->unsignedTinyInteger('period_months')->nullable();
            $table->string('plan_period', 20)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->enum('status', ['trial', 'active', 'canceled', 'expired', 'superseded']);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('renewed_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('price_before_discount', 10, 2)->nullable();
            $table->decimal('initialization_cost', 10, 2)->nullable();
            $table->decimal('tax_rate', 5, 4)->nullable();
            $table->decimal('tax_value', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->string('coupon_code')->nullable();
            $table->decimal('coupon_amount', 10, 4)->nullable();
            $table->enum('store_type', ['development', 'demo', 'live'])->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('last_event_at')->nullable();
            $table->unsignedBigInteger('current_plan_lock')->nullable()->storedAs(
                "CASE WHEN item_type = 'plan' AND status IN ('trial','active') THEN merchant_id ELSE NULL END"
            );
            $table->timestamps();

            $table->unique('current_plan_lock');
            $table->unique(['merchant_id', 'salla_subscription_id', 'item_key'], 'subs_merchant_salla_item_unique');
            $table->index(['merchant_id', 'status', 'ends_at']);
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
