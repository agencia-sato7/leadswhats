<?php

namespace App\Services\Domain;

use App\Contracts\Intelligence\ConversationAnalyzer;
use App\Data\Intelligence\ConversationAnalysisInput;
use App\Models\Conversation;
use App\Models\ConversationQualityScore;
use App\Models\KanbanColumn;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ConversationIntelligenceService
{
    public function __construct(
        private readonly ConversationAnalyzer $analyzer,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listForCompany(int $companyId, array $filters): array
    {
        $query = Conversation::query()
            ->where('company_id', $companyId)
            ->with(['lead', 'owner', 'latestQualityScore.recommendedKanbanColumn'])
            ->withCount('qualityScores');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereHas('lead', static function (Builder $leadQuery) use ($search): void {
                $leadQuery->where(function (Builder $nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('phone_e164', 'like', "%{$search}%");
                });
            });
        }

        $status = $filters['analysis_status'] ?? null;
        if ($status === 'analyzed') {
            $query->whereHas('latestQualityScore');
        } elseif ($status === 'pending') {
            $query->whereDoesntHave('latestQualityScore');
        }

        if (isset($filters['min_score'])) {
            $query->whereHas('latestQualityScore', static fn (Builder $scoreQuery) => $scoreQuery
                ->where('score', '>=', (int) $filters['min_score']));
        }

        if (isset($filters['max_score'])) {
            $query->whereHas('latestQualityScore', static fn (Builder $scoreQuery) => $scoreQuery
                ->where('score', '<=', (int) $filters['max_score']));
        }

        if (isset($filters['owner_user_id'])) {
            $ownerId = (int) $filters['owner_user_id'];
            $query->where(function (Builder $ownerQuery) use ($ownerId): void {
                $ownerQuery->where('owner_user_id', $ownerId)
                    ->orWhereHas('lead', static fn (Builder $leadQuery) => $leadQuery->where('owner_user_id', $ownerId));
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $paginator = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $currentStages = $this->currentStagesForLeads(
            $companyId,
            $paginator->getCollection()->pluck('lead_id')->map(static fn ($id) => (int) $id)->all(),
        );

        $items = $paginator->getCollection()->map(function (Conversation $conversation) use ($currentStages): array {
            $latestScore = $conversation->latestQualityScore;

            return [
                'conversation_id' => $conversation->id,
                'lead_id' => $conversation->lead_id,
                'lead_name' => $conversation->lead?->name,
                'phone' => $conversation->lead?->phone_e164,
                'source' => $conversation->lead?->source,
                'owner_user_id' => $conversation->owner_user_id ?? $conversation->lead?->owner_user_id,
                'owner_name' => $conversation->owner?->name,
                'status' => $conversation->status,
                'started_at' => $conversation->started_at?->toISOString(),
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'current_stage' => $currentStages[$conversation->lead_id] ?? null,
                'analysis_status' => $latestScore ? 'analyzed' : 'pending',
                'analysis_count' => (int) $conversation->quality_scores_count,
                'latest_analysis' => $latestScore ? $this->scorePayload($latestScore) : null,
            ];
        })->values()->all();

        return [
            'data' => $items,
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detailForCompany(int $companyId, int $conversationId): ?array
    {
        $conversation = Conversation::query()
            ->where('company_id', $companyId)
            ->with([
                'lead',
                'owner',
                'messages' => static fn ($query) => $query->orderBy('sent_at')->orderBy('id'),
                'qualityScores.recommendedKanbanColumn',
            ])
            ->find($conversationId);

        if (!$conversation) {
            return null;
        }

        $currentStage = $this->currentStagesForLeads($companyId, [(int) $conversation->lead_id])[$conversation->lead_id] ?? null;
        $analyses = $conversation->qualityScores->map(fn (ConversationQualityScore $score) => $this->scorePayload($score))->values()->all();

        return [
            'conversation_id' => $conversation->id,
            'status' => $conversation->status,
            'started_at' => $conversation->started_at?->toISOString(),
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'lead' => [
                'lead_id' => $conversation->lead_id,
                'name' => $conversation->lead?->name,
                'phone' => $conversation->lead?->phone_e164,
                'source' => $conversation->lead?->source,
                'creative_id' => $conversation->lead?->creative_id,
                'creative_url' => $conversation->lead?->creative_url,
                'campaign_name' => $conversation->lead?->campaign_name,
                'current_stage' => $currentStage,
            ],
            'owner' => [
                'owner_user_id' => $conversation->owner_user_id ?? $conversation->lead?->owner_user_id,
                'name' => $conversation->owner?->name,
            ],
            'messages' => $conversation->messages->map(static fn ($message): array => [
                'id' => $message->id,
                'direction' => $message->direction,
                'channel' => $message->channel,
                'body' => $message->body,
                'audio_transcript' => $message->audio_transcript,
                'sent_at' => $message->sent_at?->toISOString(),
            ])->values()->all(),
            'analysis_status' => $analyses === [] ? 'pending' : 'analyzed',
            'latest_analysis' => $analyses[0] ?? null,
            'analysis_history' => $analyses,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForCompany(int $companyId): array
    {
        $latestScoreIds = DB::table('conversation_quality_scores')
            ->selectRaw('MAX(id)')
            ->where('company_id', $companyId)
            ->groupBy('conversation_id');

        $latestScores = DB::table('conversation_quality_scores')
            ->where('company_id', $companyId)
            ->whereIn('id', $latestScoreIds);

        $totalConversations = Conversation::query()->where('company_id', $companyId)->count();
        $analyzedConversations = (clone $latestScores)->count();

        $topIntents = (clone $latestScores)
            ->select('intent', DB::raw('count(*) as total'))
            ->whereNotNull('intent')
            ->groupBy('intent')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(static fn ($row): array => [
                'intent' => (string) $row->intent,
                'total' => (int) $row->total,
            ])
            ->all();

        return [
            'data' => [
                'total_conversations' => $totalConversations,
                'analyzed_conversations' => $analyzedConversations,
                'pending_conversations' => max($totalConversations - $analyzedConversations, 0),
                'total_snapshots' => ConversationQualityScore::query()->where('company_id', $companyId)->count(),
                'average_score' => $analyzedConversations > 0 ? round((float) (clone $latestScores)->avg('score'), 1) : null,
                'score_bands' => [
                    'excellent' => (clone $latestScores)->where('score', '>=', 80)->count(),
                    'attention' => (clone $latestScores)->whereBetween('score', [60, 79])->count(),
                    'critical' => (clone $latestScores)->where('score', '<', 60)->count(),
                ],
                'top_intents' => $topIntents,
            ],
        ];
    }

    public function analyzeForCompany(int $companyId, int $conversationId): ConversationQualityScore
    {
        $conversation = Conversation::query()
            ->where('company_id', $companyId)
            ->with('lead')
            ->findOrFail($conversationId);

        $messages = $conversation->messages()
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            throw new InvalidArgumentException('A conversa precisa ter mensagens antes de ser analisada.');
        }

        [$currentColumnId, $columns] = $this->kanbanContext($companyId, (int) $conversation->lead_id);

        $messagePayload = $messages->map(static fn ($message): array => [
            'id' => (int) $message->id,
            'direction' => (string) $message->direction,
            'channel' => (string) $message->channel,
            'body' => $message->body,
            'audio_transcript' => $message->audio_transcript,
            'sent_at' => $message->sent_at?->toISOString() ?? (string) $message->sent_at,
        ])->all();

        $transcript = $this->buildTranscript($messagePayload);
        $result = $this->analyzer->analyze(new ConversationAnalysisInput(
            companyId: $companyId,
            conversationId: $conversation->id,
            leadId: $conversation->lead_id,
            transcript: $transcript,
            messages: $messagePayload,
            currentKanbanColumnId: $currentColumnId,
            kanbanColumns: $columns->all(),
        ));

        $validColumnIds = $columns->pluck('id')->map(static fn ($id) => (int) $id)->all();
        if ($result->recommendedKanbanColumnId !== null && !in_array($result->recommendedKanbanColumnId, $validColumnIds, true)) {
            throw new InvalidArgumentException('O analisador recomendou uma coluna que não pertence ao pipeline da conversa.');
        }

        return DB::transaction(function () use ($companyId, $conversation, $messages, $transcript, $result): ConversationQualityScore {
            Conversation::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($conversation->id);

            $analysisVersion = ((int) ConversationQualityScore::query()
                ->where('conversation_id', $conversation->id)
                ->max('analysis_version')) + 1;

            return ConversationQualityScore::query()->create([
                'company_id' => $companyId,
                'conversation_id' => $conversation->id,
                'lead_id' => $conversation->lead_id,
                'owner_user_id' => $conversation->owner_user_id ?? $conversation->lead?->owner_user_id,
                'score' => $result->score,
                'summary' => $result->summary,
                'intent' => $result->intent,
                'objections' => $result->objections,
                'positive_points' => $result->positivePoints,
                'errors' => $result->errors,
                'improvement_suggestion' => $result->improvementSuggestion,
                'commercial_data' => $result->commercialData,
                'criteria_scores' => $result->criteriaScores,
                'recommended_kanban_column_id' => $result->recommendedKanbanColumnId,
                'classification_reason' => $result->classificationReason,
                'confidence' => $result->confidence,
                'analysis_version' => $analysisVersion,
                'prompt_version' => $result->promptVersion,
                'model_provider' => $result->modelProvider,
                'model_name' => $result->modelName,
                'source_last_message_id' => $messages->last()?->id,
                'transcript_hash' => hash('sha256', $transcript),
                'analyzed_at' => now(),
            ])->load('recommendedKanbanColumn');
        });
    }

    /**
     * @param array<int, array{id:int,direction:string,channel:string,body:?string,audio_transcript:?string,sent_at:string}> $messages
     */
    private function buildTranscript(array $messages): string
    {
        return collect($messages)->map(static function (array $message): string {
            $speaker = $message['direction'] === 'inbound' ? 'Cliente' : 'Atendente';
            $content = trim((string) ($message['body'] ?? ''));
            $audioTranscript = trim((string) ($message['audio_transcript'] ?? ''));

            if ($audioTranscript !== '') {
                $content = trim($content.' [Transcrição de áudio: '.$audioTranscript.']');
            }

            return sprintf('[%s | %s] %s', $speaker, $message['sent_at'], $content);
        })->implode("\n");
    }

    /**
     * @return array{0:?int,1:Collection<int, array{id:int,name:string,rule_prompt:?string}>}
     */
    private function kanbanContext(int $companyId, int $leadId): array
    {
        $latestStage = LeadStageHistory::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $leadId)
            ->latest('id')
            ->first();

        $currentColumn = $latestStage
            ? KanbanColumn::query()->where('company_id', $companyId)->find($latestStage->to_column_id)
            : null;

        $pipelineId = $currentColumn?->pipeline_id
            ?? Pipeline::query()->where('company_id', $companyId)->orderByDesc('is_default')->value('id');

        if (!$pipelineId) {
            return [null, collect()];
        }

        $columns = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->where('pipeline_id', $pipelineId)
            ->orderBy('position')
            ->get(['id', 'name', 'rule_prompt'])
            ->map(static fn (KanbanColumn $column): array => [
                'id' => (int) $column->id,
                'name' => (string) $column->name,
                'rule_prompt' => $column->rule_prompt,
            ]);

        return [$currentColumn?->id, $columns];
    }

    /**
     * @param int[] $leadIds
     * @return array<int, string>
     */
    private function currentStagesForLeads(int $companyId, array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        $latestIds = LeadStageHistory::query()
            ->selectRaw('MAX(id)')
            ->where('company_id', $companyId)
            ->whereIn('lead_id', $leadIds)
            ->groupBy('lead_id');

        return LeadStageHistory::query()
            ->where('lead_stage_histories.company_id', $companyId)
            ->whereIn('lead_stage_histories.id', $latestIds)
            ->join('kanban_columns', 'kanban_columns.id', '=', 'lead_stage_histories.to_column_id')
            ->pluck('kanban_columns.name', 'lead_stage_histories.lead_id')
            ->mapWithKeys(static fn ($name, $leadId): array => [(int) $leadId => (string) $name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function scorePayload(ConversationQualityScore $score): array
    {
        return [
            'id' => $score->id,
            'analysis_version' => $score->analysis_version,
            'score' => $score->score,
            'summary' => $score->summary,
            'intent' => $score->intent,
            'objections' => $score->objections ?? [],
            'positive_points' => $score->positive_points ?? [],
            'errors' => $score->errors ?? [],
            'improvement_suggestion' => $score->improvement_suggestion,
            'commercial_data' => $score->commercial_data ?? [],
            'criteria_scores' => $score->criteria_scores ?? [],
            'recommended_kanban_column_id' => $score->recommended_kanban_column_id,
            'recommended_kanban_column_name' => $score->recommendedKanbanColumn?->name,
            'classification_reason' => $score->classification_reason,
            'confidence' => $score->confidence,
            'prompt_version' => $score->prompt_version,
            'model_provider' => $score->model_provider,
            'model_name' => $score->model_name,
            'source_last_message_id' => $score->source_last_message_id,
            'transcript_hash' => $score->transcript_hash,
            'analyzed_at' => $score->analyzed_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
