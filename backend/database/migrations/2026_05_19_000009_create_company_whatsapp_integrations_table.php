<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("company_whatsapp_integrations", function (Blueprint $table) {
            $table->id();
            $table->foreignId("company_id")->constrained()->cascadeOnDelete()->unique();
            $table->string("provider")->default("meta_cloud");
            $table->string("status")->default("not_configured");
            $table->string("phone_number")->nullable();
            $table->string("phone_number_id")->nullable();
            $table->string("business_account_id")->nullable();
            $table->text("access_token_encrypted")->nullable();
            $table->string("webhook_verify_token")->nullable();
            $table->timestamp("connected_at")->nullable();
            $table->text("last_error")->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("company_whatsapp_integrations");
    }
};
