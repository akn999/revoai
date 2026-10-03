<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keyed by the Salla merchant id with no foreign key, so the wallet survives the data purge.
        Schema::create('merchant_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->unique();
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('reserved')->default(0);
            $table->dateTime('starter_granted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_packs', function (Blueprint $table) {
            $table->id();
            $table->string('salla_addon_slug')->unique();
            $table->unsignedInteger('credits');
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('credit_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('merchant_wallets')->cascadeOnDelete();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->unsignedInteger('amount');
            $table->string('status', 20)->default('active');
            $table->string('action', 50);
            $table->string('price_source', 20)->default('default');
            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('purchase_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained('merchant_wallets')->cascadeOnDelete();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->foreignId('pack_id')->nullable()->constrained('credit_packs')->nullOnDelete();
            $table->unsignedInteger('credits');
            $table->string('status', 20)->default('created');
            $table->string('salla_order_id')->nullable()->unique();
            $table->string('verifier_strategy', 30)->nullable();
            $table->json('result')->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedInteger('reversal_shortfall')->default(0);
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('merchant_wallets')->cascadeOnDelete();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->bigInteger('reserved_delta')->default(0);
            $table->bigInteger('reserved_after')->default(0);
            $table->foreignId('reservation_id')->nullable()->constrained('credit_reservations')->nullOnDelete();
            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->text('reason')->nullable();
            $table->string('actor', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('purchase_intents');
        Schema::dropIfExists('credit_reservations');
        Schema::dropIfExists('credit_packs');
        Schema::dropIfExists('merchant_wallets');
    }
};
