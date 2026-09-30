<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->enum('item_type', ['plan', 'addon'])->default('plan');
            $table->string('salla_plan_name')->nullable()->index();
            $table->string('salla_item_slug')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->enum('billing_cycle', ['monthly', 'yearly', 'one_time', 'trial', 'custom']);
            $table->unsignedTinyInteger('period_months')->nullable();
            $table->decimal('price', 10, 2);
            $table->char('currency', 3)->default('SAR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['plan_id', 'billing_cycle', 'period_months']);
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 100);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['plan_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
