<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_business_settings', function (Blueprint $table) {
            $table->unsignedInteger('first_response_sla_minutes')->default(15)->after('rescue_threshold_hours');
            $table->unsignedInteger('follow_up_sla_hours')->default(24)->after('first_response_sla_minutes');
            $table->unsignedInteger('stale_conversation_hours')->default(48)->after('follow_up_sla_hours');
        });
    }

    public function down(): void
    {
        Schema::table('company_business_settings', function (Blueprint $table) {
            $table->dropColumn([
                'first_response_sla_minutes',
                'follow_up_sla_hours',
                'stale_conversation_hours',
            ]);
        });
    }
};
