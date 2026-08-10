<?php

namespace App\Services\Domain;

use App\Models\ConversationAnalysisDecision;
use App\Models\ConversationQualityScore;
use App\Models\KanbanColumn;
use App\Models\LeadStageHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KanbanRecommendationService
{
    public function __construct(private readonly KanbanMovementService $movementService) {}

    /**
     * @return array{decision:ConversationAnalysisDecision,movement:array<string,mixed>}
     */
    public function apply(User $actor, int $leadId, int $analysisId): array
    {
        return DB::transaction(function () use ($actor, $leadId, $analysisId): array {
            $analysis = $this->latestAnalysisForLead((int) $actor->company_id, $leadId, $analysisId);
            $recommendedColumnId = $analysis->recommended_kanban_column_id;

            if ($recommendedColumnId === null) {
                throw ValidationException::withMessages([
                    'recommendation' => 'Esta análise não possui uma etapa recomendada.',
                ]);
            }

            $existing = $analysis->recommendationDecision()->lockForUpdate()->first();
            if ($existing) {
                return $this->existingApplyResult($existing, $leadId, (int) $recommendedColumnId, (int) $actor->id);
            }

            $fromColumnId = $this->currentColumnId((int) $actor->company_id, $leadId);
            $movement = $this->movementService->moveLeadToColumn(
                (int) $actor->company_id,
                $leadId,
                (int) $recommendedColumnId,
                (int) $actor->id,
                'Recomendação da IA aceita pelo gestor.',
                'ai_recommendation_accepted',
            );

            $decision = ConversationAnalysisDecision::query()->create([
                'company_id' => $actor->company_id,
                'conversation_quality_score_id' => $analysis->id,
                'conversation_id' => $analysis->conversation_id,
                'lead_id' => $leadId,
                'decided_by_user_id' => $actor->id,
                'decision' => 'applied',
                'from_column_id' => $fromColumnId,
                'recommended_column_id' => $recommendedColumnId,
                'applied_column_id' => $recommendedColumnId,
                'reason' => 'Recomendação da IA aceita pelo gestor.',
                'decided_at' => now(),
            ]);

            return compact('decision', 'movement');
        });
    }

    public function keepCurrent(User $actor, int $leadId, int $analysisId): ConversationAnalysisDecision
    {
        return DB::transaction(function () use ($actor, $leadId, $analysisId): ConversationAnalysisDecision {
            $analysis = $this->latestAnalysisForLead((int) $actor->company_id, $leadId, $analysisId);
            $existing = $analysis->recommendationDecision()->lockForUpdate()->first();

            if ($existing) {
                if ($existing->decision !== 'kept_current') {
                    throw ValidationException::withMessages([
                        'recommendation' => 'Esta recomendação já foi aplicada.',
                    ]);
                }

                return $existing;
            }

            $currentColumnId = $this->currentColumnId((int) $actor->company_id, $leadId);

            return ConversationAnalysisDecision::query()->create([
                'company_id' => $actor->company_id,
                'conversation_quality_score_id' => $analysis->id,
                'conversation_id' => $analysis->conversation_id,
                'lead_id' => $leadId,
                'decided_by_user_id' => $actor->id,
                'decision' => 'kept_current',
                'from_column_id' => $currentColumnId,
                'recommended_column_id' => $analysis->recommended_kanban_column_id,
                'applied_column_id' => $currentColumnId,
                'reason' => 'Etapa atual mantida pelo gestor.',
                'decided_at' => now(),
            ]);
        });
    }

    private function latestAnalysisForLead(int $companyId, int $leadId, int $analysisId): ConversationQualityScore
    {
        $analysis = ConversationQualityScore::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $leadId)
            ->findOrFail($analysisId);

        $latestId = ConversationQualityScore::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $leadId)
            ->orderByDesc('analyzed_at')
            ->orderByDesc('id')
            ->value('id');

        if ((int) $latestId !== (int) $analysis->id) {
            throw ValidationException::withMessages([
                'recommendation' => 'A recomendação não pertence à análise mais recente deste lead.',
            ]);
        }

        if ($analysis->recommended_kanban_column_id !== null) {
            KanbanColumn::query()
                ->where('company_id', $companyId)
                ->findOrFail($analysis->recommended_kanban_column_id);
        }

        return $analysis;
    }

    private function currentColumnId(int $companyId, int $leadId): ?int
    {
        $columnId = LeadStageHistory::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $leadId)
            ->orderByDesc('id')
            ->value('to_column_id');

        return $columnId === null ? null : (int) $columnId;
    }

    /**
     * @return array{decision:ConversationAnalysisDecision,movement:array<string,mixed>}
     */
    private function existingApplyResult(ConversationAnalysisDecision $decision, int $leadId, int $columnId, int $actorId): array
    {
        if ($decision->decision !== 'applied') {
            throw ValidationException::withMessages([
                'recommendation' => 'A etapa atual já foi mantida para esta recomendação.',
            ]);
        }

        return [
            'decision' => $decision,
            'movement' => [
                'lead_id' => $leadId,
                'kanban_column_id' => $columnId,
                'moved_by_user_id' => $actorId,
                'movement_type' => 'ai_recommendation_accepted',
                'history_created' => false,
            ],
        ];
    }
}
