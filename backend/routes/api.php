<?php

use App\Http\Controllers\Api\AdminCompanyController;
use App\Http\Controllers\Api\AdminCompanyViewContextController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminAccessProfileController;
use App\Http\Controllers\Api\AdminPermissionController;
use App\Http\Controllers\Api\AdminAccessAuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CampaignIntelligenceController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationIntelligenceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\KanbanColumnController;
use App\Http\Controllers\Api\KanbanRecommendationController;
use App\Http\Controllers\Api\LeadOwnerController;
use App\Http\Controllers\Api\LeadSourceController;
use App\Http\Controllers\Api\LeadStageController;
use App\Http\Controllers\Api\LeadStageHistoryController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\SettingsWhatsAppController;
use App\Http\Controllers\Api\TaskChecklistController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    if (! app()->environment('production')) {
        Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'ingest']);
    }
    Route::get('/webhooks/whatsapp/meta', [WhatsappWebhookController::class, 'verifyMeta']);
    Route::post('/webhooks/whatsapp/meta', [WhatsappWebhookController::class, 'ingestMeta']);

    Route::middleware('auth.token')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        Route::middleware('password.changed')->group(function () {

        Route::get('/admin/companies', [AdminCompanyController::class, 'index'])
            ->middleware('role:platform_admin');
        Route::post('/admin/companies', [AdminCompanyController::class, 'store'])
            ->middleware('role:platform_admin');
        Route::get('/admin/companies/{companyId}', [AdminCompanyController::class, 'show'])
            ->middleware('role:platform_admin');
        Route::patch('/admin/companies/{companyId}', [AdminCompanyController::class, 'update'])
            ->middleware('role:platform_admin');
        Route::post('/admin/companies/{companyId}/view-context', [AdminCompanyViewContextController::class, 'store'])
            ->middleware('role:platform_admin');
        Route::delete('/admin/view-context', [AdminCompanyViewContextController::class, 'destroy'])
            ->middleware('role:platform_admin');
        Route::get('/admin/users', [AdminUserController::class, 'index'])->middleware('role:platform_admin');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->middleware('role:platform_admin');
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->middleware('role:platform_admin');
        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->middleware('role:platform_admin');
        Route::post('/admin/users/{user}/restore', [AdminUserController::class, 'restore'])->middleware('role:platform_admin');
        Route::post('/admin/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->middleware('role:platform_admin');
        Route::get('/admin/permissions', [AdminPermissionController::class, 'index'])->middleware('role:platform_admin');
        Route::get('/admin/access-profiles', [AdminAccessProfileController::class, 'index'])->middleware('role:platform_admin');
        Route::post('/admin/access-profiles', [AdminAccessProfileController::class, 'store'])->middleware('role:platform_admin');
        Route::patch('/admin/access-profiles/{accessProfile}', [AdminAccessProfileController::class, 'update'])->middleware('role:platform_admin');
        Route::delete('/admin/access-profiles/{accessProfile}', [AdminAccessProfileController::class, 'destroy'])->middleware('role:platform_admin');
        Route::post('/admin/access-profiles/{accessProfile}/copy', [AdminAccessProfileController::class, 'copy'])->middleware('role:platform_admin');
        Route::post('/admin/access-profiles/{accessProfile}/sync-preview', [AdminAccessProfileController::class, 'syncPreview'])->middleware('role:platform_admin');
        Route::post('/admin/access-profiles/{accessProfile}/sync', [AdminAccessProfileController::class, 'sync'])->middleware('role:platform_admin');
        Route::get('/admin/access-audits', [AdminAccessAuditController::class, 'index'])->middleware('role:platform_admin');

        Route::get('/bootstrap/overview', [BootstrapController::class, 'overview'])
            ->name('tenant.bootstrap.overview')
            ->middleware(['permission:dashboard.view', 'tenant.view']);

        Route::get('/settings/whatsapp', [SettingsWhatsAppController::class, 'show'])
            ->name('tenant.settings.whatsapp.show')
            ->middleware(['permission:whatsapp_settings.view', 'tenant.view']);
        Route::post('/settings/whatsapp/disconnect', [SettingsWhatsAppController::class, 'disconnect'])
            ->name('tenant.settings.whatsapp.disconnect')
            ->middleware('permission:whatsapp_settings.manage');
        Route::post('/settings/whatsapp/coexistence/complete', [SettingsWhatsAppController::class, 'completeCoexistence'])
            ->name('tenant.settings.whatsapp.coexistence.complete')
            ->middleware('permission:whatsapp_settings.manage');
        Route::post('/settings/whatsapp/coexistence/sync', [SettingsWhatsAppController::class, 'requestSync'])
            ->name('tenant.settings.whatsapp.coexistence.sync')
            ->middleware('permission:whatsapp_settings.manage');

        // Intelligence lê conversas já persistidas e não depende do estado da conexão WhatsApp.
        Route::get('/intelligence/conversations', [ConversationIntelligenceController::class, 'index'])
            ->name('tenant.intelligence.conversations.index')
            ->middleware(['permission:conversation_intelligence.view', 'tenant.view']);
        Route::get('/intelligence/conversations/{conversation}', [ConversationIntelligenceController::class, 'show'])
            ->name('tenant.intelligence.conversations.show')
            ->middleware(['permission:conversation_intelligence.view', 'tenant.view']);
        Route::get('/intelligence/summary', [ConversationIntelligenceController::class, 'summary'])
            ->name('tenant.intelligence.summary')
            ->middleware(['permission:conversation_intelligence.view', 'tenant.view']);
        Route::post('/intelligence/conversations/{conversation}/analyze', [ConversationIntelligenceController::class, 'analyze'])
            ->middleware('permission:conversation_intelligence.analyze');

        Route::get('/intelligence/campaign-reports/preview', [CampaignIntelligenceController::class, 'preview'])
            ->name('tenant.intelligence.campaign-reports.preview')
            ->middleware(['permission:campaign_intelligence.view', 'tenant.view']);
        Route::get('/intelligence/campaign-reports', [CampaignIntelligenceController::class, 'index'])
            ->name('tenant.intelligence.campaign-reports.index')
            ->middleware(['permission:campaign_intelligence.view', 'tenant.view']);
        Route::post('/intelligence/campaign-reports', [CampaignIntelligenceController::class, 'store'])
            ->middleware('permission:campaign_intelligence.analyze');
        Route::get('/intelligence/campaign-reports/{report}', [CampaignIntelligenceController::class, 'show'])
            ->name('tenant.intelligence.campaign-reports.show')
            ->middleware(['permission:campaign_intelligence.view', 'tenant.view']);
        Route::get('/intelligence/campaign-reports/{report}/leads', [CampaignIntelligenceController::class, 'leads'])
            ->name('tenant.intelligence.campaign-reports.leads')
            ->middleware(['permission:campaign_intelligence.view', 'tenant.view']);

        // Estas rotas trabalham somente com dados já persistidos. A conexão com a
        // Meta é exigida apenas nos fluxos que efetivamente chamam a API oficial.
        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
            ->name('tenant.dashboard.summary')
            ->middleware(['permission:dashboard.view', 'tenant.view']);

        Route::get('/contacts', [ContactController::class, 'index'])
            ->name('tenant.contacts.index')
            ->middleware(['permission:contacts.view', 'tenant.view']);
        Route::get('/contacts/export', [ContactController::class, 'export'])
            ->middleware('permission:contacts.export');

        Route::get('/inbox/conversations', [InboxController::class, 'index'])
            ->name('tenant.inbox.conversations.index')
            ->middleware(['permission:conversations.view,attendance.view', 'tenant.view']);

        Route::get('/inbox/conversations/{conversationId}', [InboxController::class, 'show'])
            ->name('tenant.inbox.conversations.show')
            ->middleware(['permission:conversations.view,attendance.view', 'tenant.view']);
        Route::get('/inbox/conversations/{conversationId}/events', [InboxController::class, 'events'])
            ->name('tenant.inbox.conversations.events')
            ->middleware(['permission:conversations.view', 'tenant.view']);
        Route::post('/inbox/conversations/{conversationId}/messages', [InboxController::class, 'sendMessage'])
            ->middleware('permission:conversations.respond,attendance.respond');
        Route::get('/inbox/attachments/{attachmentId}', [InboxController::class, 'attachment'])
            ->name('tenant.inbox.attachments.show')
            ->middleware(['permission:conversations.view,attendance.view', 'tenant.view']);

        Route::get('/tasks/checklist', [TaskChecklistController::class, 'index'])
            ->name('tenant.tasks.checklist')
            ->middleware(['permission:followups.view', 'tenant.view']);

        Route::get('/users/assignable', [UserController::class, 'assignable'])
            ->middleware('permission:crm.manage');

        Route::get('/pipelines', [PipelineController::class, 'index'])
            ->name('tenant.pipelines.index')
            ->middleware(['permission:crm.view', 'tenant.view']);

        Route::get('/pipelines/{pipelineId}/kanban', [PipelineController::class, 'kanban'])
            ->name('tenant.pipelines.kanban')
            ->middleware(['permission:crm.view', 'tenant.view']);

        Route::get('/leads/{leadId}/stage-history', [LeadStageHistoryController::class, 'index'])
            ->name('tenant.leads.stage-history')
            ->middleware(['permission:crm.view', 'tenant.view']);

        Route::patch('/leads/{leadId}/stage', [LeadStageController::class, 'update'])
            ->middleware('permission:crm.manage');

        Route::patch('/leads/{leadId}/owner', [LeadOwnerController::class, 'update'])
            ->middleware('permission:crm.manage');

        Route::post('/leads/{leadId}/recommendations/{analysisId}/apply', [KanbanRecommendationController::class, 'apply'])
            ->middleware('permission:crm.manage');
        Route::post('/leads/{leadId}/recommendations/{analysisId}/keep-current', [KanbanRecommendationController::class, 'keepCurrent'])
            ->middleware('permission:crm.manage');

        Route::post('/pipelines/{pipelineId}/columns', [KanbanColumnController::class, 'store'])
            ->middleware('permission:crm.manage');

        Route::patch('/kanban-columns/{columnId}', [KanbanColumnController::class, 'update'])
            ->middleware('permission:crm.manage');

        Route::delete('/kanban-columns/{columnId}', [KanbanColumnController::class, 'destroy'])
            ->middleware('permission:crm.manage');

        // Classificação de origem é uma operação de gestão.
        // Atendimento (SDR) não pode classificar/reclassificar.
        Route::get('/leads/sources/unknown', [LeadSourceController::class, 'unknown'])
            ->middleware('permission:contacts.classify');

        Route::get('/leads/sources/recent', [LeadSourceController::class, 'recent'])
            ->middleware('permission:contacts.classify');

        Route::patch('/leads/{leadId}/source', [LeadSourceController::class, 'classify'])
            ->middleware('permission:contacts.classify');
        });
    });
});
