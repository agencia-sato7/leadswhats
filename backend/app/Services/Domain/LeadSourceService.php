<?php

namespace App\Services\Domain;

use App\Models\Lead;
use App\Models\LeadSourceHistory;

class LeadSourceService
{
    public function applyInitialSource(Lead $lead, string $source): void
    {
        $lead->source = $source;
        $lead->source_method = 'auto';
        $lead->source_updated_at = now();

        LeadSourceHistory::create([
            'company_id' => $lead->company_id,
            'lead_id' => $lead->id,
            'previous_source' => null,
            'new_source' => $source,
            'change_type' => 'auto',
            'reason' => 'Classificação inicial via webhook.',
            'changed_at' => now(),
        ]);
    }

    public function applyAutoSourceFromReentry(Lead $lead, string $source): void
    {
        if ($source === 'desconhecido' || $lead->source === $source) {
            return;
        }

        $this->trackAndApplyChange($lead, $source, 'auto', 'Reentrada detectada com origem rastreada.', null);
    }

    public function applyAiSource(Lead $lead, string $source, string $reason): void
    {
        if ($source === 'desconhecido' || $lead->source === $source) {
            return;
        }

        $this->trackAndApplyChange($lead, $source, 'auto', $reason, null);
    }

    public function classifyManual(Lead $lead, string $newSource, ?int $changedByUserId, ?string $reason): void
    {
        if ($lead->source !== $newSource) {
            $this->trackAndApplyChange(
                $lead,
                $newSource,
                'manual',
                $reason ?? 'Classificação manual pelo atendente.',
                $changedByUserId,
            );
        }

        $lead->source_method = 'manual';
        $lead->source_updated_at = now();
        $lead->save();
    }

    private function trackAndApplyChange(
        Lead $lead,
        string $newSource,
        string $changeType,
        string $reason,
        ?int $changedByUserId,
    ): void {
        LeadSourceHistory::create([
            'company_id' => $lead->company_id,
            'lead_id' => $lead->id,
            'previous_source' => $lead->source,
            'new_source' => $newSource,
            'change_type' => $changeType,
            'reason' => $reason,
            'changed_by_user_id' => $changedByUserId,
            'changed_at' => now(),
        ]);

        $lead->source = $newSource;
        $lead->source_method = $changeType;
        $lead->source_updated_at = now();
        $lead->save();
    }
}
