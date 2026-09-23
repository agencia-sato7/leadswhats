<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('report_date');
            $table->json('metrics')->nullable();
            $table->json('quality')->nullable();
            $table->text('executive_summary')->nullable();
            $table->string('overall_verdict', 40)->nullable();
            $table->json('report_payload')->nullable();
            $table->json('recipients')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'report_date'], 'daily_reports_company_date_unique');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
