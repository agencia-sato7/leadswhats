<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_intelligence_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_name')->nullable();
            $table->string('stage_name')->nullable();
            $table->date('analysis_date');
            $table->char('input_hash', 64);
            $table->unsignedSmallInteger('score');
            $table->json('criteria_scores');
            $table->text('summary');
            $table->json('positive_points');
            $table->json('errors');
            $table->text('improvement_suggestion')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('inbound_count')->default(0);
            $table->unsignedInteger('outbound_count')->default(0);
            $table->unsignedInteger('rescue_attempts')->default(0);
            $table->string('prompt_version', 80);
            $table->string('model_provider', 80)->nullable();
            $table->string('model_name', 120)->nullable();
            $table->timestamp('analyzed_at');
            $table->timestamps();

            $table->unique(['company_id', 'lead_id', 'analysis_date', 'input_hash'], 'campaign_evidence_unique');
            $table->index(['company_id', 'analysis_date']);
        });

        Schema::create('campaign_intelligence_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('base_report_id')->nullable()->constrained('campaign_intelligence_reports')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('comparison_start_date');
            $table->date('comparison_end_date');
            $table->string('status', 24)->default('queued');
            $table->string('progress_stage', 40)->default('queued');
            $table->unsignedSmallInteger('progress_percentage')->default(0);
            $table->char('input_fingerprint', 64)->nullable();
            $table->json('metrics')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('reused_evidence_count')->default(0);
            $table->unsignedInteger('new_evidence_count')->default(0);
            $table->string('prompt_version', 80)->nullable();
            $table->string('model_provider', 80)->nullable();
            $table->string('model_name', 120)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'start_date', 'end_date']);
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('campaign_intelligence_report_evidence', function (Blueprint $table) {
            $table->foreignId('campaign_intelligence_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_intelligence_evidence_id')->constrained()->cascadeOnDelete();
            $table->string('cohort', 16);
            $table->timestamps();

            $table->primary(['campaign_intelligence_report_id', 'campaign_intelligence_evidence_id'], 'campaign_report_evidence_pk');
            $table->index(['campaign_intelligence_report_id', 'cohort'], 'campaign_report_evidence_cohort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_intelligence_report_evidence');
        Schema::dropIfExists('campaign_intelligence_reports');
        Schema::dropIfExists('campaign_intelligence_evidences');
    }
};
