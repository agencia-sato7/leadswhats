<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\ContactDirectoryService;
use App\Services\EffectiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    public function index(
        Request $request,
        ContactDirectoryService $contactDirectoryService,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $filters = $request->validate($this->filterRules(includePagination: true));

        $result = $contactDirectoryService->listForUser(
            $request->user(),
            $filters,
            $tenantContext->companyId($request),
        );

        return response()->json($result);
    }

    public function export(Request $request, ContactDirectoryService $contactDirectoryService): StreamedResponse
    {
        $filters = $request->validate($this->filterRules(includePagination: false));
        $rows = $contactDirectoryService->exportRowsForUser($request->user(), $filters);

        $fileName = 'contacts_export_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(static function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fputcsv($output, ['phone', 'name', 'source', 'classification', 'current_stage', 'created_at', 'last_message_at']);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['phone'] ?? null,
                    $row['name'] ?? null,
                    $row['source'] ?? null,
                    $row['classification'] ?? null,
                    $row['current_stage'] ?? null,
                    $row['created_at'] ?? null,
                    $row['last_message_at'] ?? null,
                ]);
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function filterRules(bool $includePagination): array
    {
        $rules = [
            'search' => ['sometimes', 'string', 'max:120'],
            'source' => ['sometimes', 'string', 'max:60'],
            'classification' => ['sometimes', 'string', 'in:lead_novo,lead_repetido'],
            'stage_id' => ['sometimes', 'integer', 'min:1'],
            'kanban_column_id' => ['sometimes', 'integer', 'min:1'],
            'has_unknown_source' => ['sometimes', 'in:true,false,1,0'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date'],
            'last_message_from' => ['sometimes', 'date'],
            'last_message_to' => ['sometimes', 'date'],
        ];

        if ($includePagination) {
            $rules['page'] = ['sometimes', 'integer', 'min:1'];
            $rules['per_page'] = ['sometimes', 'integer', 'min:1', 'max:100'];
        }

        return $rules;
    }
}
