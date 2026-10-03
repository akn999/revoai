<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // The status enum gains syncing and purged; a plain string keeps it extensible.
            $table->string('status', 20)->default('pending')->change();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('default_language', 5)->default('ar')->after('domain');
            $table->json('enabled_languages')->nullable()->after('default_language');
            $table->string('plan_code', 50)->nullable()->index()->after('enabled_languages');
            $table->string('plan_status', 20)->nullable()->after('plan_code');
            $table->string('salla_plan_ref')->nullable()->after('plan_status');
            $table->dateTime('purge_at')->nullable()->index();
            $table->dateTime('last_webhook_at')->nullable();
            $table->boolean('reauth_required')->default(false);
            $table->unsignedInteger('sync_pages_done')->default(0);
            $table->unsignedInteger('sync_total_pages')->nullable();
            $table->dateTime('sync_started_at')->nullable();
            $table->dateTime('sync_finished_at')->nullable();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->json('feature_flags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('feature_flags');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'default_language', 'enabled_languages', 'plan_code', 'plan_status', 'salla_plan_ref', 'purge_at',
                'last_webhook_at', 'reauth_required', 'sync_pages_done', 'sync_total_pages', 'sync_started_at', 'sync_finished_at',
            ]);
        });
    }
};
