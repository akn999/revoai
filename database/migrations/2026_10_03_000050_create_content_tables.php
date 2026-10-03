<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->unsignedBigInteger('salla_user_id')->nullable();
            $table->json('filter');
            $table->json('fields');
            $table->json('languages');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('estimate')->default(0);
            $table->string('status', 20)->default('running');
            $table->timestamps();
        });

        Schema::create('content_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 5);
            $table->string('kind', 10)->default('full');
            $table->json('fields');
            $table->string('status', 20)->default('queued');
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->json('output')->nullable();
            $table->json('manual_fields')->nullable();
            $table->text('instruction')->nullable();
            $table->text('keywords')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('credits')->default(0);
            $table->foreignId('reservation_id')->nullable()->constrained('credit_reservations')->nullOnDelete();
            $table->foreignId('bulk_job_id')->nullable()->constrained('bulk_jobs')->nullOnDelete();
            $table->unsignedBigInteger('salla_user_id')->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['product_id', 'lang', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 5);
            $table->string('field', 40);
            $table->longText('value')->nullable();
            $table->string('source', 20);
            $table->foreignId('generation_id')->nullable()->constrained('content_generations')->nullOnDelete();
            $table->dateTime('pushed_at')->nullable();
            $table->unsignedBigInteger('salla_user_id')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'lang', 'field']);
        });

        Schema::create('product_image_alts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->foreignId('product_image_id')->constrained('product_images')->cascadeOnDelete();
            $table->string('lang', 5);
            $table->string('alt');
            $table->foreignId('generation_id')->nullable()->constrained('content_generations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_image_id', 'lang']);
        });
    }

    public function down(): void
    {
        foreach (['product_image_alts', 'content_versions', 'content_generations', 'bulk_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
