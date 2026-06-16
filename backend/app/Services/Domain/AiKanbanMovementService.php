<?php

namespace App\Services\Domain;

use App\Models\KanbanColumn;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Services\WhatsApp\AiRuleEvaluatorService;

class AiKanbanMovementService
{
    public function __construct(
        private readonly KanbanMovementService $kanbanMovementService,
        private readonly AiRuleEvaluatorService $aiRuleEvaluatorService,
    ) {
    }

    /**
     * Evaluates the lead's history and moves it to a matching stage if rules match.
     *
     * @param int $companyId
     * @param int $leadId
     * @return array{moved: bool, target_column_id: int|null, reason: string|null}
     */
    public function evaluateAndMove(int $companyId, int $leadId): array
    {
        // 1. Get latest stage history to determine current column and pipeline
        $latestStage = LeadStageHistory::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $leadId)
            ->orderByDesc('id')
            ->first();

        if (!$latestStage) {
            return [
                'moved' => false,
                'target_column_id' => null,
                'reason' => 'Sem histórico de estágio para o lead.',
            ];
        }

        $currentColumn = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->find($latestStage->to_column_id);

        if (!$currentColumn) {
            return [
                'moved' => false,
                'target_column_id' => null,
                'reason' => 'Coluna atual não encontrada.',
            ];
        }

        // 2. Fetch candidate columns in the same pipeline that have rules configured
        $candidateColumns = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->where('pipeline_id', $currentColumn->pipeline_id)
            ->whereNotNull('rule_prompt')
            ->where('rule_prompt', '!=', '')
            ->get(['id', 'rule_prompt']);

        if ($candidateColumns->isEmpty()) {
            return [
                'moved' => false,
                'target_column_id' => null,
                'reason' => 'Nenhuma regra configurada nas colunas deste pipeline.',
            ];
        }

        $columnRules = [];
        foreach ($candidateColumns as $col) {
            $columnRules[$col->id] = $col->rule_prompt;
        }

        // 3. Retrieve last 10 messages for context
        $messages = Message::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $leadId)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            return [
                'moved' => false,
                'target_column_id' => null,
                'reason' => 'Sem mensagens no histórico do lead.',
            ];
        }

        $historyText = "";
        foreach ($messages as $msg) {
            $sender = $msg->direction === 'inbound' ? 'Cliente' : 'Vendedor';
            $content = $msg->body;
            if ($msg->audio_transcript) {
                $content .= " [Audio Transcript: " . $msg->audio_transcript . "]";
            }
            $historyText .= "[{$sender}]: {$content}\n";
        }

        // 4. Evaluate rules
        $result = $this->aiRuleEvaluatorService->evaluate($historyText, $columnRules);
        $matchedColumnId = $result['matched_column_id'];
        $reason = $result['reason'];

        if (!$matchedColumnId) {
            return [
                'moved' => false,
                'target_column_id' => null,
                'reason' => $reason ?? 'Nenhuma regra de estágio satisfeita.',
            ];
        }

        // 5. If matched column is different from current column, execute movement
        if ((int) $matchedColumnId === (int) $currentColumn->id) {
            return [
                'moved' => false,
                'target_column_id' => $matchedColumnId,
                'reason' => 'O lead já está no estágio correspondente à regra identificada.',
            ];
        }

        $this->kanbanMovementService->moveLeadToColumnByAi(
            $companyId,
            $leadId,
            $matchedColumnId,
            $reason
        );

        return [
            'moved' => true,
            'target_column_id' => $matchedColumnId,
            'reason' => $reason,
        ];
    }
}
