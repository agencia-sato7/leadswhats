<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("company_business_settings", function (Blueprint $table) {
            $table->id();
            $table->foreignId("company_id")->unique()->constrained("companies")->cascadeOnDelete();
            $table->string("timezone")->default("America/Sao_Paulo");
            $table->time("workday_start_time")->default("08:00:00");
            $table->time("workday_end_time")->default("18:00:00");
            $table->time("lunch_start_time")->nullable()->default("12:00:00");
            $table->time("lunch_end_time")->nullable()->default("13:00:00");
            $table->json("working_days")->default(json_encode([1, 2, 3, 4, 5]));
            $table->unsignedInteger("repeated_lead_window_days")->default(90);
            $table->unsignedInteger("rescue_threshold_hours")->default(24);
            $table->string("webhook_token")->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("company_business_settings");
    }
};
