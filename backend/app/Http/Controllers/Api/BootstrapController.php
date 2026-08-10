<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Pipeline;
use App\Services\CompanyWhatsAppIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;
        $company = Company::find($companyId);
        $fakeIsActive = (string) config('whatsapp.provider') === 'fake'
            && (string) config('app.env') !== 'production';
        $whatsAppConfigured = $fakeIsActive
            || CompanyWhatsAppIntegrationService::isConfigured($company?->whatsappIntegration);

        return response()->json([
            'company' => $company,
            'demo_mode' => app()->environment(['local', 'testing']),
            'whatsapp_status' => $whatsAppConfigured ? 'configured' : 'not_configured',
            'counts' => [
                'users' => $company ? $company->users()->count() : 0,
                'leads' => Lead::where('company_id', $companyId)->count(),
                'conversations' => Conversation::where('company_id', $companyId)->count(),
                'messages' => Message::where('company_id', $companyId)->count(),
                'pipelines' => Pipeline::where('company_id', $companyId)->count(),
                'kanban_columns' => KanbanColumn::where('company_id', $companyId)->count(),
            ],
        ]);
    }
}
