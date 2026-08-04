<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("company_whatsapp_integrations", function (Blueprint $table) {
            $table->string("integration_type", 20)->default("meta_cloud")->after("provider");
            $table->string("session_status", 20)->nullable()->after("status");
            $table->text("qr_code_base64")->nullable()->after("session_status");
            $table->string("baileys_phone")->nullable()->after("qr_code_base64");
        });
    }

    public function down(): void
    {
        Schema::table("company_whatsapp_integrations", function (Blueprint $table) {
            $table->dropColumn(["integration_type", "session_status", "qr_code_base64", "baileys_phone"]);
        });
    }
};