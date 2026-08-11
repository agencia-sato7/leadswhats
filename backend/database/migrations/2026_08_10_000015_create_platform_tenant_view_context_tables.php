<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_tenant_view_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['platform_admin_user_id', 'revoked_at', 'expires_at'],
                'platform_view_context_admin_status_idx'
            );
        });

        Schema::create('platform_tenant_view_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_tenant_view_context_id')
                ->nullable()
                ->constrained('platform_tenant_view_contexts')
                ->nullOnDelete();
            $table->foreignId('platform_admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('reason', 80)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(
                ['platform_admin_user_id', 'company_id', 'occurred_at'],
                'platform_view_audit_actor_company_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_tenant_view_audits');
        Schema::dropIfExists('platform_tenant_view_contexts');
    }
};
