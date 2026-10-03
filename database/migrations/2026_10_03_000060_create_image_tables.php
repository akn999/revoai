<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['merchant_id', 'name']);
        });

        Schema::create('media_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['merchant_id', 'name']);
        });

        Schema::create('image_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('source_image_id')->nullable();
            $table->unsignedBigInteger('parent_generated_image_id')->nullable();
            $table->text('source_url');
            $table->string('source_key');
            $table->foreignId('preset_id')->nullable()->constrained('presets')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->text('instruction')->nullable();
            $table->longText('composed_prompt')->nullable();
            $table->json('params')->nullable();
            $table->unsignedTinyInteger('variants')->default(1);
            $table->string('status', 20)->default('queued');
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('credits')->default(0);
            $table->foreignId('reservation_id')->nullable()->constrained('credit_reservations')->nullOnDelete();
            $table->unsignedBigInteger('salla_user_id')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('generated_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->foreignId('generation_id')->nullable()->constrained('image_generations')->nullOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreignId('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('source', 20)->default('generated');
            $table->string('disk', 20);
            $table->string('path')->nullable();
            $table->string('mime', 50);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('attached_product_id')->nullable();
            $table->unsignedBigInteger('attached_salla_image_id')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'product_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('media_taggables', function (Blueprint $table) {
            $table->foreignId('generated_image_id')->constrained('generated_images')->cascadeOnDelete();
            $table->foreignId('media_tag_id')->constrained('media_tags')->cascadeOnDelete();
            $table->primary(['generated_image_id', 'media_tag_id']);
        });
    }

    public function down(): void
    {
        foreach (['media_taggables', 'generated_images', 'image_generations', 'media_tags', 'media_folders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
