<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['trial', 'start', 'renewal']);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('renew_date')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('tax_value', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->string('coupon_code')->nullable();
            $table->foreignId('app_event_id')->nullable()->constrained('app_events')->nullOnDelete();
            $table->timestamps();
            $table->unique(['subscription_id', 'starts_at', 'kind']);
        });

        Schema::create('subscription_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 100);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['subscription_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_features');
        Schema::dropIfExists('subscription_periods');
    }
};
