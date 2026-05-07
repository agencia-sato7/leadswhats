<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LeadStageHistoryController;
use App\Http\Controllers\Api\LeadSourceController;
use App\Http\Controllers\Api\LeadStageController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'ingest']);

    Route::middleware('auth.token')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/bootstrap/overview', [BootstrapController::class, 'overview'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/pipelines', [PipelineController::class, 'index'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/pipelines/{pipelineId}/kanban', [PipelineController::class, 'kanban'])
            ->middleware('role:admin,gestor,sdr');

        Route::get('/leads/{leadId}/stage-history', [LeadStageHistoryController::class, 'index'])
            ->middleware('role:admin,gestor,sdr');

        Route::patch('/leads/{leadId}/stage', [LeadStageController::class, 'update'])
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
