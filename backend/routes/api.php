<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LeadSourceController;
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
