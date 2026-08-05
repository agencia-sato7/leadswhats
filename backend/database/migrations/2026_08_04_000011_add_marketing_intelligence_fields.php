<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('creative_id')->nullable()->after('source_updated_at');
            $table->string('creative_url')->nullable()->after('creative_id');
            $table->text('creative_description')->nullable()->after('creative_url');
            $table->string('campaign_name')->nullable()->after('creative_description');
        });

        Schema::create('lead_creative_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('creative_id')->nullable();
            $table->string('creative_url')->nullable();
            $table->string('platform')->nullable();
            $table->text('description')->nullable();
            $table->string('headline')->nullable();
            $table->string('cta')->nullable();
            $table->string('image_url')->nullable();
            $table->string('status')->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'platform']);
            $table->index(['lead_id']);
        });

        Schema::table('company_business_settings', function (Blueprint $table) {
            $table->json('lookalike_export_stage_ids')->nullable()->after('webhook_token');
        });
    }

    public function down(): void
    {
        Schema::table('company_business_settings', function (Blueprint $table) {
            $table->dropColumn(['lookalike_export_stage_ids']);
        });

        Schema::dropIfExists('lead_creative_analyses');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['creative_id', 'creative_url', 'creative_description', 'campaign_name']);
        });
    }
};