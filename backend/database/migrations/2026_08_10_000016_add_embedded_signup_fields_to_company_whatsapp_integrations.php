<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_whatsapp_integrations', function (Blueprint $table): void {
            $table->string('waba_id')->nullable()->after('business_account_id');
            $table->string('business_id')->nullable()->after('waba_id');
            $table->json('page_ids')->nullable()->after('business_id');
            $table->json('catalog_ids')->nullable()->after('page_ids');
            $table->json('dataset_ids')->nullable()->after('catalog_ids');
            $table->json('instagram_account_ids')->nullable()->after('dataset_ids');
        });
    }

    public function down(): void
    {
        Schema::table('company_whatsapp_integrations', function (Blueprint $table): void {
            $table->dropColumn([
                'waba_id',
                'business_id',
                'page_ids',
                'catalog_ids',
                'dataset_ids',
                'instagram_account_ids',
            ]);
        });
    }
};
