<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_quality_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('score');
            $table->text('summary');
            $table->string('intent')->nullable();
            $table->json('objections')->nullable();
            $table->json('positive_points')->nullable();
            $table->json('errors')->nullable();
            $table->text('improvement_suggestion')->nullable();
            $table->json('commercial_data')->nullable();
            $table->json('criteria_scores')->nullable();
            $table->foreignId('recommended_kanban_column_id')
                ->nullable()
                ->constrained('kanban_columns')
                ->nullOnDelete();
            $table->text('classification_reason')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedInteger('analysis_version');
            $table->string('prompt_version');
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();
            $table->foreignId('source_last_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->char('transcript_hash', 64);
            $table->timestamp('analyzed_at');
            $table->timestamps();

            $table->unique(['conversation_id', 'analysis_version']);
            $table->index(['company_id', 'analyzed_at']);
            $table->index(['company_id', 'score']);
            $table->index(['owner_user_id', 'analyzed_at']);
            $table->index(['conversation_id', 'analyzed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_quality_scores');
    }
};
