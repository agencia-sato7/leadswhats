<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_analysis_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_quality_score_id')
                ->unique()
                ->constrained('conversation_quality_scores')
                ->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32);
            $table->foreignId('from_column_id')->nullable()->constrained('kanban_columns')->nullOnDelete();
            $table->foreignId('recommended_column_id')->nullable()->constrained('kanban_columns')->nullOnDelete();
            $table->foreignId('applied_column_id')->nullable()->constrained('kanban_columns')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['company_id', 'lead_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_analysis_decisions');
    }
};
