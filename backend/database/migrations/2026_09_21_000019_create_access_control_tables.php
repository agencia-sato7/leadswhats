<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('group_name');
            $table->timestamps();
        });

        Schema::create('access_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('source_profile_id')->nullable()->constrained('access_profiles')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('data_scope')->default('company');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_full_access')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('access_profile_permission', function (Blueprint $table) {
            $table->foreignId('access_profile_id')->constrained('access_profiles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['access_profile_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('access_profile_id')->nullable()->after('company_id')->constrained('access_profiles')->nullOnDelete();
            $table->boolean('must_change_password')->default(false)->after('password');
        });

        Schema::create('access_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('event');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        $now = now();
        foreach (PermissionCatalog::all() as $code => $meta) {
            DB::table('permissions')->insert([
                'code' => $code, 'name' => $meta['name'], 'group_name' => $meta['group'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $templates = [
            ['name' => 'Administrador', 'slug' => 'administrador', 'description' => 'Acesso total e protegido.', 'scope' => 'company', 'system' => true, 'full' => true, 'permissions' => array_keys(PermissionCatalog::all())],
            ['name' => 'Gestor', 'slug' => 'gestor', 'description' => 'Gestão completa da operação.', 'scope' => 'company', 'system' => false, 'full' => false, 'permissions' => PermissionCatalog::gestor()],
            ['name' => 'SDR', 'slug' => 'sdr', 'description' => 'Operação limitada aos próprios dados.', 'scope' => 'own', 'system' => false, 'full' => false, 'permissions' => PermissionCatalog::sdr()],
            ['name' => 'Conector WhatsApp', 'slug' => 'conector-whatsapp', 'description' => 'Acesso exclusivo à conexão do WhatsApp.', 'scope' => 'company', 'system' => false, 'full' => false, 'permissions' => PermissionCatalog::whatsappConnector()],
        ];

        $permissionIds = DB::table('permissions')->pluck('id', 'code');
        $templateIds = [];
        foreach ($templates as $template) {
            $id = DB::table('access_profiles')->insertGetId([
                'company_id' => null, 'source_profile_id' => null, 'name' => $template['name'],
                'slug' => $template['slug'], 'description' => $template['description'],
                'data_scope' => $template['scope'], 'version' => 1, 'is_system' => $template['system'],
                'is_full_access' => $template['full'], 'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $templateIds[$template['slug']] = $id;
            foreach ($template['permissions'] as $code) {
                DB::table('access_profile_permission')->insert(['access_profile_id' => $id, 'permission_id' => $permissionIds[$code]]);
            }
        }

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ($templates as $template) {
                $profileId = DB::table('access_profiles')->insertGetId([
                    'company_id' => $companyId, 'source_profile_id' => $templateIds[$template['slug']],
                    'name' => $template['name'], 'slug' => $template['slug'], 'description' => $template['description'],
                    'data_scope' => $template['scope'], 'version' => 1, 'is_system' => $template['system'],
                    'is_full_access' => $template['full'], 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($template['permissions'] as $code) {
                    DB::table('access_profile_permission')->insert(['access_profile_id' => $profileId, 'permission_id' => $permissionIds[$code]]);
                }
                $legacyRole = $template['slug'] === 'administrador' ? 'admin' : $template['slug'];
                if (in_array($legacyRole, ['admin', 'gestor', 'sdr'], true)) {
                    DB::table('users')->where('company_id', $companyId)->where('role', $legacyRole)->update(['access_profile_id' => $profileId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_audit_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_profile_id');
            $table->dropColumn('must_change_password');
        });
        Schema::dropIfExists('access_profile_permission');
        Schema::dropIfExists('access_profiles');
        Schema::dropIfExists('permissions');
    }
};
