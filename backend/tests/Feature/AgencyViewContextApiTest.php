<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\PlatformTenantViewContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgencyViewContextApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_starts_short_read_only_context_and_entry_is_audited(): void
    {
        $platformAdmin = $this->createUser(null, 'platform_admin', 'platform.context@test.local');
        $company = Company::query()->create([
            'name' => 'Clínica Contexto',
            'slug' => 'clinica-contexto',
            'active' => true,
        ]);

        $response = $this->withToken($this->login($platformAdmin))
            ->postJson("/api/v1/admin/companies/{$company->id}/view-context")
            ->assertCreated()
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.company.name', 'Clínica Contexto')
            ->assertJsonPath('data.read_only', true);

        $plainContext = (string) $response->json('data.context_token');
        $this->assertStringStartsWith('ptvc_', $plainContext);
        $this->assertNotSame('', (string) $response->json('data.expires_at'));
        $this->assertNull($platformAdmin->fresh()->company_id);

        $context = PlatformTenantViewContext::query()->firstOrFail();
        $this->assertSame($platformAdmin->id, $context->platform_admin_user_id);
        $this->assertSame($company->id, $context->company_id);
        $this->assertSame(hash('sha256', $plainContext), $context->token_hash);
        $this->assertTrue($context->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));

        $this->assertDatabaseHas('platform_tenant_view_audits', [
            'platform_tenant_view_context_id' => $context->id,
            'platform_admin_user_id' => $platformAdmin->id,
            'company_id' => $company->id,
            'event' => 'entered',
            'reason' => 'selected_company',
        ]);
    }

    public function test_context_rejects_missing_or_inactive_company(): void
    {
        $platformAdmin = $this->createUser(null, 'platform_admin', 'platform.inactive@test.local');
        $inactiveCompany = Company::query()->create([
            'name' => 'Clínica Inativa',
            'slug' => 'clinica-inativa',
            'active' => false,
        ]);
        $token = $this->login($platformAdmin);

        $this->withToken($token)
            ->postJson("/api/v1/admin/companies/{$inactiveCompany->id}/view-context")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_id']);

        $this->withToken($token)
            ->postJson('/api/v1/admin/companies/999999/view-context')
            ->assertNotFound();

        $this->assertDatabaseCount('platform_tenant_view_contexts', 0);
    }

    public function test_tenant_users_cannot_manage_context_and_header_is_rejected(): void
    {
        $company = Company::query()->create(['name' => 'Tenant', 'slug' => 'tenant-context-header']);

        foreach (['admin', 'gestor', 'sdr'] as $role) {
            $user = $this->createUser($company->id, $role, "{$role}.context@test.local");
            $token = $this->login($user);

            $this->withToken($token)
                ->postJson("/api/v1/admin/companies/{$company->id}/view-context")
                ->assertForbidden();

            $this->withHeaders([
                'Authorization' => 'Bearer '.$token,
                'X-Tenant-Context' => 'context-not-allowed',
            ])->getJson('/api/v1/dashboard/summary')->assertForbidden();
        }
    }

    public function test_platform_context_can_read_explicit_allowlist_for_selected_company(): void
    {
        [$company, $pipeline, $lead, $conversation] = $this->createTenantDataset('allowlist');
        $platformAdmin = $this->createUser(null, 'platform_admin', 'platform.allowlist@test.local');
        $headers = $this->contextHeaders($platformAdmin, $company);

        $paths = [
            '/api/v1/bootstrap/overview',
            '/api/v1/dashboard/summary',
            '/api/v1/contacts',
            '/api/v1/inbox/conversations',
            "/api/v1/inbox/conversations/{$conversation->id}",
            "/api/v1/inbox/conversations/{$conversation->id}/events",
            '/api/v1/tasks/checklist',
            '/api/v1/pipelines',
            "/api/v1/pipelines/{$pipeline->id}/kanban",
            "/api/v1/leads/{$lead->id}/stage-history",
            '/api/v1/intelligence/summary',
            '/api/v1/intelligence/conversations',
            "/api/v1/intelligence/conversations/{$conversation->id}",
            '/api/v1/settings/whatsapp',
        ];

        foreach ($paths as $path) {
            $this->withHeaders($headers)->getJson($path)->assertOk();
        }

        $this->withHeaders($headers)
            ->getJson('/api/v1/bootstrap/overview')
            ->assertJsonPath('company.id', $company->id);

        $this->assertDatabaseCount('conversation_events', 0);
    }

    public function test_context_preserves_cross_tenant_isolation_and_is_bound_to_actor(): void
    {
        [$companyA] = $this->createTenantDataset('tenant-a');
        [, , , $conversationB] = $this->createTenantDataset('tenant-b');
        $platformAdminA = $this->createUser(null, 'platform_admin', 'platform.a@test.local');
        $platformAdminB = $this->createUser(null, 'platform_admin', 'platform.b@test.local');
        $headersA = $this->contextHeaders($platformAdminA, $companyA);

        $this->withHeaders($headersA)
            ->getJson("/api/v1/inbox/conversations/{$conversationB->id}")
            ->assertNotFound();

        $contextToken = $headersA['X-Tenant-Context'];
        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->login($platformAdminB),
            'X-Tenant-Context' => $contextToken,
        ])->getJson('/api/v1/dashboard/summary')->assertForbidden();
    }

    public function test_expired_and_revoked_contexts_are_rejected_and_exit_is_audited(): void
    {
        $company = Company::query()->create(['name' => 'Clínica Revogação', 'slug' => 'clinica-revogacao']);
        $platformAdmin = $this->createUser(null, 'platform_admin', 'platform.revoke@test.local');
        $headers = $this->contextHeaders($platformAdmin, $company);

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/admin/view-context')
            ->assertOk()
            ->assertJsonPath('message', 'Contexto de clínica encerrado.');

        $this->assertNotNull(PlatformTenantViewContext::query()->firstOrFail()->revoked_at);
        $this->assertDatabaseHas('platform_tenant_view_audits', [
            'platform_admin_user_id' => $platformAdmin->id,
            'company_id' => $company->id,
            'event' => 'exited',
            'reason' => 'user_exit',
        ]);
        $this->withHeaders($headers)->getJson('/api/v1/dashboard/summary')->assertForbidden();

        $freshHeaders = $this->contextHeaders($platformAdmin, $company);
        PlatformTenantViewContext::query()
            ->whereNull('revoked_at')
            ->update(['expires_at' => now()->subSecond()]);
        $this->withHeaders($freshHeaders)->getJson('/api/v1/dashboard/summary')->assertForbidden();
    }

    public function test_platform_context_cannot_use_operational_mutations_or_export(): void
    {
        [$company] = $this->createTenantDataset('read-only');
        $platformAdmin = $this->createUser(null, 'platform_admin', 'platform.readonly@test.local');
        $headers = $this->contextHeaders($platformAdmin, $company);

        $requests = [
            ['get', '/api/v1/contacts/export', []],
            ['post', '/api/v1/intelligence/conversations/1/analyze', []],
            ['patch', '/api/v1/leads/1/owner', ['owner_user_id' => null, 'reason' => 'blocked']],
            ['patch', '/api/v1/leads/1/stage', ['kanban_column_id' => 1]],
            ['patch', '/api/v1/leads/1/source', ['source' => 'google']],
            ['post', '/api/v1/settings/whatsapp/coexistence/complete', []],
            ['post', '/api/v1/settings/whatsapp/coexistence/sync', ['sync_type' => 'both']],
            ['post', '/api/v1/pipelines/1/columns', ['name' => 'Blocked']],
            ['patch', '/api/v1/kanban-columns/1', ['name' => 'Blocked']],
            ['delete', '/api/v1/kanban-columns/1', []],
            ['post', '/api/v1/leads/1/recommendations/1/apply', []],
            ['post', '/api/v1/leads/1/recommendations/1/keep-current', []],
        ];

        foreach ($requests as [$method, $path, $payload]) {
            $this->withHeaders($headers)->json(strtoupper($method), $path, $payload)->assertForbidden();
        }
    }

    /**
     * @return array{Company,Pipeline,Lead,Conversation}
     */
    private function createTenantDataset(string $suffix): array
    {
        $company = Company::query()->create([
            'name' => 'Clínica '.$suffix,
            'slug' => 'clinica-'.$suffix,
            'active' => true,
        ]);
        $pipeline = Pipeline::query()->create([
            'company_id' => $company->id,
            'name' => 'Pipeline '.$suffix,
            'is_default' => true,
        ]);
        $column = KanbanColumn::query()->create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo contato',
            'position' => 1,
        ]);
        $lead = Lead::query()->create([
            'company_id' => $company->id,
            'name' => 'Lead '.$suffix,
            'phone_e164' => '+55119999'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT),
            'source' => 'google',
        ]);
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'status' => 'active',
            'started_at' => now()->subHour(),
            'last_message_at' => now(),
        ]);
        Message::query()->create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'provider' => 'fake',
            'direction' => 'inbound',
            'body' => 'Olá',
            'sent_at' => now(),
        ]);
        LeadStageHistory::query()->create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'to_column_id' => $column->id,
            'move_source' => 'system',
            'moved_at' => now(),
        ]);

        return [$company, $pipeline, $lead, $conversation];
    }

    /** @return array<string,string> */
    private function contextHeaders(User $platformAdmin, Company $company): array
    {
        $authToken = $this->login($platformAdmin);
        $contextToken = (string) $this->withToken($authToken)
            ->postJson("/api/v1/admin/companies/{$company->id}/view-context")
            ->assertCreated()
            ->json('data.context_token');

        return [
            'Authorization' => 'Bearer '.$authToken,
            'X-Tenant-Context' => $contextToken,
        ];
    }

    private function createUser(?int $companyId, string $role, string $email): User
    {
        return User::query()->create([
            'company_id' => $companyId,
            'name' => strtoupper($role),
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => $role,
            'active' => true,
        ]);
    }

    private function login(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->assertOk()->json('token');
    }
}
