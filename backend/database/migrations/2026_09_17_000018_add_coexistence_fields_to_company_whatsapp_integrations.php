<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_whatsapp_integrations', function (Blueprint $table): void {
            // Modo de conexão usado pela empresa. O default preserva as linhas
            // já existentes (conexão antiga por credenciais/Embedded Signup).
            $table->string('connection_mode')->default('embedded_signup')->after('status');
            $table->timestamp('coexistence_opted_in_at')->nullable()->after('connected_at');
            $table->string('history_sync_status')->nullable()->after('coexistence_opted_in_at');
            $table->string('contacts_sync_status')->nullable()->after('history_sync_status');
            $table->timestamp('token_expires_at')->nullable()->after('contacts_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('company_whatsapp_integrations', function (Blueprint $table): void {
            $table->dropColumn([
                'connection_mode',
                'coexistence_opted_in_at',
                'history_sync_status',
                'contacts_sync_status',
                'token_expires_at',
            ]);
        });
    }
};