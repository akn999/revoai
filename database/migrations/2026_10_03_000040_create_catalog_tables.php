<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('salla_product_id');
            $table->string('sku')->nullable();
            $table->string('mpn')->nullable();
            $table->string('gtin')->nullable();
            $table->string('type', 30)->nullable();
            $table->string('status', 30)->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->integer('quantity')->nullable();
            $table->boolean('hide_quantity')->default(false);
            $table->boolean('is_available')->default(true);
            $table->json('categories')->nullable();
            $table->json('brand')->nullable();
            $table->json('tags')->nullable();
            $table->json('options')->nullable();
            $table->json('skus')->nullable();
            $table->json('urls')->nullable();
            $table->json('promotion')->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw')->nullable();
            $table->char('relevant_hash', 64)->nullable();
            $table->dateTime('last_event_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'salla_product_id']);
            $table->index(['merchant_id', 'status']);
        });

        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 5);
            $table->string('name')->nullable();
            $table->longText('description')->nullable();
            $table->string('promotion_title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('metadata_title')->nullable();
            $table->string('metadata_description')->nullable();
            $table->string('metadata_url')->nullable();
            $table->dateTime('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'lang']);
        });

        Schema::create('product_contexts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('facts')->nullable();
            $table->text('summary')->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->boolean('stale')->default(true);
            $table->dateTime('built_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('salla_image_id');
            $table->text('url');
            $table->string('alt')->nullable();
            $table->boolean('main')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->text('three_d_image_url')->nullable();
            $table->unsignedBigInteger('generated_image_id')->nullable();
            $table->dateTime('tombstoned_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'salla_image_id']);
            $table->index(['merchant_id', 'salla_image_id']);
        });

        Schema::create('image_analyses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->string('source_key', 120);
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->text('analysis');
            $table->timestamps();

            $table->unique(['merchant_id', 'source_key']);
        });
    }

    public function down(): void
    {
        foreach (['image_analyses', 'product_images', 'product_contexts', 'product_translations', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
