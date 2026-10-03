<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable()->index(); // null = default preset
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('prompt');
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->json('params')->nullable();
            $table->unsignedInteger('price_override')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('prompt_defaults', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('label');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('store_prompts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('key', 50);
            $table->text('body');
            $table->timestamps();

            $table->unique(['merchant_id', 'key']);
        });

        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->unique();
            $table->json('languages')->nullable();
            $table->boolean('auto_publish')->default(false);
            $table->string('description_length', 10)->default('medium');
            $table->string('description_structure', 10)->default('paragraphs');
            $table->json('models')->nullable();
            $table->json('image_defaults')->nullable();
            $table->unsignedTinyInteger('default_variants')->default(1);
            $table->timestamps();
        });

        Schema::create('store_contexts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->unique();
            $table->string('store_name')->nullable();
            $table->string('tagline')->nullable();
            $table->string('slogan')->nullable();
            $table->string('industry', 50)->nullable();
            $table->json('audience')->nullable();
            $table->string('brand_tone', 50)->nullable();
            $table->string('photography_style', 50)->nullable();
            $table->json('brand_colors')->nullable();
            $table->text('delivery_policy')->nullable();
            $table->text('return_policy')->nullable();
            $table->boolean('returns_accepted')->default(false);
            $table->text('privacy_policy')->nullable();
            $table->json('faq')->nullable();
            $table->timestamps();
        });

        Schema::create('store_context_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->string('label');
            $table->text('text');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('context_options', function (Blueprint $table) {
            $table->id();
            $table->string('field', 50);
            $table->string('key', 50);
            $table->string('label_ar');
            $table->string('label_en');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['field', 'key']);
        });

        Schema::create('moderation_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description');
            $table->text('instruction');
            $table->text('allowed')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('moderation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->unsignedBigInteger('salla_user_id')->nullable();
            $table->string('subject_type', 50);
            $table->string('subject_id', 64)->nullable();
            $table->string('category', 50)->nullable();
            $table->string('provider', 20);
            $table->string('decision', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['merchant_id', 'created_at']);
            $table->index(['category', 'decision']);
        });

        Schema::create('settings_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->unsignedBigInteger('salla_user_id')->nullable();
            $table->string('group', 50);
            $table->json('old')->nullable();
            $table->json('new')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('form_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('salla_user_id')->default(0);
            $table->string('form_key', 100);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['merchant_id', 'salla_user_id', 'form_key']);
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            $table->string('auditable_type', 120);
            $table->string('auditable_id', 64)->nullable();
            $table->string('event', 20);
            $table->json('old')->nullable();
            $table->json('new')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        foreach (['admin_audit_logs', 'form_drafts', 'settings_audits', 'moderation_events', 'moderation_categories', 'context_options', 'store_context_entries', 'store_contexts', 'store_settings', 'store_prompts', 'prompt_defaults', 'presets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
