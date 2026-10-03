<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('provider_model_id');
            $table->string('name_ar');
            $table->string('name_en');
            $table->json('features');
            $table->json('capabilities')->nullable();
            $table->json('prices')->nullable();
            $table->json('param_schema')->nullable();
            $table->json('cost_rates')->nullable();
            $table->boolean('active')->default(true);
            $table->json('default_for')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['provider', 'active']);
        });

        // Kept forever: no prompt or output content is ever stored here.
        Schema::create('usage_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->unsignedBigInteger('salla_user_id')->nullable();
            $table->string('feature', 50);
            $table->string('action', 50);
            $table->string('provider', 20);
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('provider_model_id');
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedSmallInteger('images')->default(0);
            $table->decimal('provider_cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->boolean('internal')->default(false);
            $table->string('status', 20)->default('ok');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['merchant_id', 'created_at']);
            $table->index(['feature', 'created_at']);
            $table->index(['ai_model_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_ledger');
        Schema::dropIfExists('ai_models');
    }
};
