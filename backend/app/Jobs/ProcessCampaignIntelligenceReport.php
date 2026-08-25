<?php

namespace App\Jobs;

use App\Models\CampaignIntelligenceReport;
use App\Services\Domain\CampaignIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessCampaignIntelligenceReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public readonly int $reportId)
    {
        $this->onQueue('campaign-intelligence');
    }

    public function handle(CampaignIntelligenceService $service): void
    {
        $report = CampaignIntelligenceReport::query()->findOrFail($this->reportId);
        if ($report->status === 'completed') {
            return;
        }
        $service->processReport($report);
    }

    public function failed(?Throwable $exception): void
    {
        $report = CampaignIntelligenceReport::query()->find($this->reportId);
        if (!$report) {
            return;
        }
        app(CampaignIntelligenceService::class)->markFailed(
            $report,
            $exception?->getMessage() ?: 'Não foi possível concluir a análise da campanha.'
        );
    }
}
