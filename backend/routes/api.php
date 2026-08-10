<?php

use App\Http\Controllers\Api\AdminCompanyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
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

        Route::get('/admin/companies', [AdminCompanyController::class, 'index'])
            ->middleware('role:platform_admin');
        Route::post('/admin/companies', [AdminCompanyController::class, 'store'])
            ->middleware('role:platform_admin');
        Route::get('/admin/companies/{companyId}', [AdminCompanyController::class, 'show'])
            ->middleware('role:platform_admin');
        Route::patch('/admin/companies/{companyId}', [AdminCompanyController::class, 'update'])
            ->middleware('role:platform_admin');

        Route::get('/bootstrap/overview', [BootstrapController::class, 'overview'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/settings/whatsapp', [SettingsWhatsAppController::class, 'show'])
            ->middleware('role:admin,gestor');
        Route::put('/settings/whatsapp', [SettingsWhatsAppController::class, 'update'])
            ->middleware('role:admin,gestor');

        // Intelligence lê conversas já persistidas e não depende do estado da conexão WhatsApp.
        Route::get('/intelligence/conversations', [ConversationIntelligenceController::class, 'index'])
            ->middleware('role:admin,gestor');
        Route::get('/intelligence/conversations/{conversation}', [ConversationIntelligenceController::class, 'show'])
            ->middleware('role:admin,gestor');
        Route::get('/intelligence/summary', [ConversationIntelligenceController::class, 'summary'])
            ->middleware('role:admin,gestor');
        Route::post('/intelligence/conversations/{conversation}/analyze', [ConversationIntelligenceController::class, 'analyze'])
            ->middleware('role:admin,gestor');

        // Estas rotas trabalham somente com dados já persistidos. A conexão com a
        // Meta é exigida apenas nos fluxos que efetivamente chamam a API oficial.
        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/contacts', [ContactController::class, 'index'])
            ->middleware('role:admin,gestor,sdr');
        Route::get('/contacts/export', [ContactController::class, 'export'])
            ->middleware('role:admin,gestor');

        Route::get('/inbox/conversations', [InboxController::class, 'index'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/inbox/conversations/{conversationId}', [InboxController::class, 'show'])
            ->middleware('role:admin,gestor,sdr');
        Route::get('/inbox/conversations/{conversationId}/events', [InboxController::class, 'events'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/tasks/checklist', [TaskChecklistController::class, 'index'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/users/assignable', [UserController::class, 'assignable'])
            ->middleware('role:admin,gestor');

        Route::get('/pipelines', [PipelineController::class, 'index'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/pipelines/{pipelineId}/kanban', [PipelineController::class, 'kanban'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/leads/{leadId}/stage-history', [LeadStageHistoryController::class, 'index'])
            ->middleware('role:admin,gestor,sdr');

        Route::patch('/leads/{leadId}/stage', [LeadStageController::class, 'update'])
            ->middleware('role:admin,gestor');

        Route::patch('/leads/{leadId}/owner', [LeadOwnerController::class, 'update'])
            ->middleware('role:admin,gestor');

        Route::post('/leads/{leadId}/recommendations/{analysisId}/apply', [KanbanRecommendationController::class, 'apply'])
            ->middleware('role:admin,gestor');
        Route::post('/leads/{leadId}/recommendations/{analysisId}/keep-current', [KanbanRecommendationController::class, 'keepCurrent'])
            ->middleware('role:admin,gestor');

        Route::post('/pipelines/{pipelineId}/columns', [KanbanColumnController::class, 'store'])
            ->middleware('role:admin,gestor');

        Route::patch('/kanban-columns/{columnId}', [KanbanColumnController::class, 'update'])
            ->middleware('role:admin,gestor');

        Route::delete('/kanban-columns/{columnId}', [KanbanColumnController::class, 'destroy'])
            ->middleware('role:admin,gestor');

        // Classificação de origem é uma operação de gestão.
        // Atendimento (SDR) não pode classificar/reclassificar.
        Route::get('/leads/sources/unknown', [LeadSourceController::class, 'unknown'])
            ->middleware('role:admin,gestor');

        Route::get('/leads/sources/recent', [LeadSourceController::class, 'recent'])
            ->middleware('role:admin,gestor');

        Route::patch('/leads/{leadId}/source', [LeadSourceController::class, 'classify'])
            ->middleware('role:admin,gestor');
    });
});
