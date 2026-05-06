<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'provider')) {
                $table->string('provider')->default('whatsapp')->after('conversation_id');
            }

            if (!Schema::hasColumn('messages', 'raw_payload')) {
                $table->json('raw_payload')->nullable()->after('external_message_id');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unique(['company_id', 'provider', 'external_message_id'], 'messages_company_provider_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_company_provider_external_unique');

            if (Schema::hasColumn('messages', 'raw_payload')) {
                $table->dropColumn('raw_payload');
            }

            if (Schema::hasColumn('messages', 'provider')) {
                $table->dropColumn('provider');
            }
        });
    }
};
