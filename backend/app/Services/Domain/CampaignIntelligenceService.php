<?php

namespace App\Services\Domain;

use App\Contracts\Intelligence\CampaignAnalyzer;
use App\Models\CampaignIntelligenceEvidence;
use App\Models\CampaignIntelligenceReport;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\User;
use App\Services\CompanySettingsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CampaignIntelligenceService
{
    private const MAX_RANGE_DAYS = 90;

    public function __construct(
        private readonly CampaignAnalyzer $analyzer,
        private readonly CompanySettingsService $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function previewForCompany(int $companyId, string $startDate, string $endDate): array
    {
        $context = $this->buildContext($companyId, $startDate, $endDate);
        $cached = CampaignIntelligenceReport::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', $context['range']['start_date'])
            ->whereDate('end_date', $context['range']['end_date'])
            ->where('status', 'completed')
            ->where('input_fingerprint', $context['fingerprint'])
            ->latest('id')
            ->first();

        return [
            'data' => [
                'range' => $context['range'],
                'metrics' => $context['metrics'],
                'has_analyzable_data' => count($context['inputs']) > 0,
                'cached_report_id' => $cached?->id,
                'cached_report_completed_at' => $cached?->completed_at?->toISOString(),
                'estimated_evidences' => count($context['inputs']),
                'reusable_evidences' => $this->existingEvidenceMap($companyId, $context['inputs'])->count(),
            ],
        ];
    }

    /** @return array{report:CampaignIntelligenceReport,reused:bool} */
    public function requestReport(int $companyId, int $userId, string $startDate, string $endDate): array
    {
        $context = $this->buildContext($companyId, $startDate, $endDate);

        if ($context['inputs'] === []) {
            throw new InvalidArgumentException('Não há leads novos ou resgatados com mensagens no período selecionado.');
        }

        return DB::transaction(function () use ($companyId, $userId, $context): array {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();

            $completed = CampaignIntelligenceReport::query()
                ->where('company_id', $companyId)
                ->whereDate('start_date', $context['range']['start_date'])
                ->whereDate('end_date', $context['range']['end_date'])
                ->where('status', 'completed')
                ->where('input_fingerprint', $context['fingerprint'])
                ->latest('id')
                ->first();

            if ($completed) {
                return ['report' => $completed, 'reused' => true];
            }

            $active = CampaignIntelligenceReport::query()
                ->where('company_id', $companyId)
                ->whereDate('start_date', $context['range']['start_date'])
                ->whereDate('end_date', $context['range']['end_date'])
                ->whereIn('status', ['queued', 'processing'])
                ->where('input_fingerprint', $context['fingerprint'])
                ->latest('id')
                ->first();

            if ($active) {
                return ['report' => $active, 'reused' => true];
            }

            $baseReport = CampaignIntelligenceReport::query()
                ->where('company_id', $companyId)
                ->whereDate('start_date', $context['range']['start_date'])
                ->whereDate('end_date', '<', $context['range']['end_date'])
                ->where('status', 'completed')
                ->orderByDesc('end_date')
                ->first();
            $existingCount = $this->existingEvidenceMap($companyId, $context['inputs'])->count();

            $report = CampaignIntelligenceReport::query()->create([
                'company_id' => $companyId,
                'requested_by_user_id' => $userId,
                'base_report_id' => $baseReport?->id,
                'start_date' => $context['range']['start_date'],
                'end_date' => $context['range']['end_date'],
                'comparison_start_date' => $context['range']['comparison_start_date'],
                'comparison_end_date' => $context['range']['comparison_end_date'],
                'status' => 'queued',
                'progress_stage' => 'queued',
                'progress_percentage' => 0,
                'input_fingerprint' => $context['fingerprint'],
                'metrics' => $context['metrics'],
                'reused_evidence_count' => $existingCount,
                'new_evidence_count' => count($context['inputs']) - $existingCount,
            ]);

            return ['report' => $report, 'reused' => false];
        });
    }

    public function processReport(CampaignIntelligenceReport $report): void
    {
        $this->progress($report, 'collecting', 10, ['status' => 'processing', 'failure_message' => null]);
        $context = $this->buildContext(
            (int) $report->company_id,
            $report->start_date->toDateString(),
            $report->end_date->toDateString(),
        );
        $existing = $this->existingEvidenceMap((int) $report->company_id, $context['inputs']);
        $missingInputs = array_values(array_filter(
            $context['inputs'],
            static fn (array $input): bool => !$existing->has($input['input_hash'])
        ));

        $this->progress($report, 'analyzing_evidence', 25, [
            'input_fingerprint' => $context['fingerprint'],
            'metrics' => $context['metrics'],
            'reused_evidence_count' => $existing->count(),
            'new_evidence_count' => count($missingInputs),
        ]);

        $completedMissing = 0;
        foreach (array_chunk($missingInputs, 20) as $chunk) {
            $analysisResults = collect($this->analyzer->analyzeEvidenceBatch(array_map(
                static fn (array $input): array => $input['analyzer_payload'],
                $chunk
            )))->keyBy('key');

            foreach ($chunk as $input) {
                $result = $analysisResults->get($input['input_hash']);
                if (!is_array($result)) {
                    throw new InvalidArgumentException('O analisador não retornou uma das evidências solicitadas.');
                }
                $evidence = $this->persistEvidence((int) $report->company_id, $input, $result);
                $existing->put($input['input_hash'], $evidence);
            }

            $completedMissing += count($chunk);
            $ratio = count($missingInputs) > 0 ? $completedMissing / count($missingInputs) : 1;
            $this->progress($report, 'analyzing_evidence', 25 + (int) round($ratio * 45));
        }

        $cohortByEvidenceId = [];
        foreach ($context['inputs'] as $input) {
            /** @var CampaignIntelligenceEvidence $evidence */
            $evidence = $existing->get($input['input_hash']);
            $cohortByEvidenceId[$evidence->id] = $input['cohort'];
            $report->evidences()->syncWithoutDetaching([
                $evidence->id => ['cohort' => $input['cohort']],
            ]);
        }

        $this->progress($report, 'consolidating', 80);
        $evidences = CampaignIntelligenceEvidence::query()
            ->whereIn('id', array_keys($cohortByEvidenceId))
            ->get();
        $quality = $this->qualityRollup($evidences);
        $newQuality = $this->qualityRollup($evidences->filter(
            fn (CampaignIntelligenceEvidence $item): bool => $cohortByEvidenceId[$item->id] === 'new'
        ));
        $rescuedQuality = $this->qualityRollup($evidences->filter(
            fn (CampaignIntelligenceEvidence $item): bool => $cohortByEvidenceId[$item->id] === 'rescued'
        ));
        $team = $this->teamRollup($evidences);
        $baseResult = $report->baseReport?->result;

        $synthesis = $this->analyzer->consolidate([
            'range' => $context['range'],
            'metrics' => $context['metrics'],
            'quality' => $quality,
            'cohorts' => ['new' => $newQuality, 'rescued' => $rescuedQuality],
            'team' => $team,
            'base_report' => $baseResult,
            'evidence_summaries' => $evidences->take(250)->map(static fn (CampaignIntelligenceEvidence $item): array => [
                'lead_id' => $item->lead_id,
                'date' => $item->analysis_date->toDateString(),
                'score' => $item->score,
                'summary' => $item->summary,
                'positive_points' => $item->positive_points,
                'errors' => $item->errors,
            ])->values()->all(),
        ]);

        $result = [
            'executive_summary' => (string) $synthesis['executive_summary'],
            'overall_verdict' => $this->normalizeVerdict((string) $synthesis['overall_verdict']),
            'volume_assessment' => [
                ...$context['metrics']['volume'],
                'summary' => (string) ($synthesis['volume_summary'] ?? ''),
            ],
            'service_quality' => [...$quality, 'summary' => (string) ($synthesis['service_summary'] ?? '')],
            'cohorts' => [
                'new' => [...$newQuality, 'summary' => (string) ($synthesis['new_leads_summary'] ?? '')],
                'rescued' => [...$rescuedQuality, 'summary' => (string) ($synthesis['rescued_leads_summary'] ?? '')],
            ],
            'team' => $team,
            'priorities' => array_values(array_filter((array) ($synthesis['priorities'] ?? []), 'is_string')),
        ];

        $this->progress($report, 'completed', 100, [
            'status' => 'completed',
            'result' => $result,
            'prompt_version' => $synthesis['prompt_version'] ?? null,
            'model_provider' => $synthesis['model_provider'] ?? null,
            'model_name' => $synthesis['model_name'] ?? null,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(CampaignIntelligenceReport $report, string $message): void
    {
        if ($report->status === 'completed') {
            return;
        }
        $report->forceFill([
            'status' => 'failed',
            'progress_stage' => 'failed',
            'failure_message' => $message,
        ])->save();
    }

    /** @return array<string, mixed> */
    public function listForCompany(int $companyId, int $page = 1): array
    {
        $paginator = CampaignIntelligenceReport::query()
            ->where('company_id', $companyId)
            ->with('requestedBy:id,name')
            ->latest('id')
            ->paginate(12, ['*'], 'page', max($page, 1));

        return [
            'data' => $paginator->getCollection()->map(fn (CampaignIntelligenceReport $report) => $this->reportPayload($report, false))->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /** @return array<string, mixed>|null */
    public function reportForCompany(int $companyId, int $reportId): ?array
    {
        $report = CampaignIntelligenceReport::query()
            ->where('company_id', $companyId)
            ->with(['requestedBy:id,name', 'baseReport:id,start_date,end_date'])
            ->find($reportId);

        return $report ? $this->reportPayload($report, true) : null;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed>|null */
    public function leadsForReport(int $companyId, int $reportId, array $filters): ?array
    {
        $report = CampaignIntelligenceReport::query()->where('company_id', $companyId)->find($reportId);
        if (!$report) {
            return null;
        }

        $query = DB::table('campaign_intelligence_report_evidence as pivot')
            ->join('campaign_intelligence_evidences as evidence', 'evidence.id', '=', 'pivot.campaign_intelligence_evidence_id')
            ->join('leads', 'leads.id', '=', 'evidence.lead_id')
            ->where('pivot.campaign_intelligence_report_id', $reportId)
            ->where('evidence.company_id', $companyId)
            ->when(isset($filters['cohort']), fn ($q) => $q->where('pivot.cohort', $filters['cohort']))
            ->when(isset($filters['owner_user_id']), function ($q) use ($filters): void {
                $ownerId = (int) $filters['owner_user_id'];
                $ownerId === 0 ? $q->whereNull('evidence.owner_user_id') : $q->where('evidence.owner_user_id', $ownerId);
            })
            ->selectRaw('evidence.lead_id, leads.name as lead_name, evidence.owner_user_id, MAX(evidence.owner_name) as owner_name, MAX(evidence.stage_name) as stage_name, pivot.cohort, ROUND(AVG(evidence.score), 1) as average_score, COUNT(evidence.id) as evidence_count, SUM(evidence.message_count) as message_count, SUM(evidence.rescue_attempts) as rescue_attempts, MAX(evidence.conversation_id) as conversation_id, MIN(evidence.analysis_date) as first_evidence_date, MAX(evidence.analysis_date) as last_evidence_date')
            ->groupBy('evidence.lead_id', 'leads.name', 'evidence.owner_user_id', 'pivot.cohort')
            ->orderByDesc('average_score');

        $paginator = $query->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1));

        $leadIds = collect($paginator->items())->pluck('lead_id')->map(static fn ($id) => (int) $id)->all();
        $reportEndUtc = Carbon::parse($report->end_date->toDateString().' 23:59:59', $this->settings->timezone($companyId))->utc();
        $latestStageIds = LeadStageHistory::query()
            ->selectRaw('MAX(id)')
            ->where('company_id', $companyId)
            ->whereIn('lead_id', $leadIds ?: [0])
            ->where('moved_at', '<=', $reportEndUtc)
            ->groupBy('lead_id');
        $stageNames = LeadStageHistory::query()
            ->where('lead_stage_histories.company_id', $companyId)
            ->whereIn('lead_stage_histories.id', $latestStageIds)
            ->join('kanban_columns', 'kanban_columns.id', '=', 'lead_stage_histories.to_column_id')
            ->pluck('kanban_columns.name', 'lead_stage_histories.lead_id');

        return [
            'data' => collect($paginator->items())->map(static fn ($row): array => [
                'lead_id' => (int) $row->lead_id,
                'lead_name' => $row->lead_name,
                'owner_user_id' => $row->owner_user_id !== null ? (int) $row->owner_user_id : null,
                'owner_name' => $row->owner_name,
                'stage_name' => $stageNames[$row->lead_id] ?? $row->stage_name,
                'cohort' => $row->cohort,
                'average_score' => (float) $row->average_score,
                'verdict' => self::verdictForScore((float) $row->average_score),
                'evidence_count' => (int) $row->evidence_count,
                'message_count' => (int) $row->message_count,
                'rescue_attempts' => (int) $row->rescue_attempts,
                'conversation_id' => $row->conversation_id !== null ? (int) $row->conversation_id : null,
                'first_evidence_date' => $row->first_evidence_date,
                'last_evidence_date' => $row->last_evidence_date,
            ])->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /** @return array<string, mixed> */
    public function reportPayload(CampaignIntelligenceReport $report, bool $includeResult = true): array
    {
        return [
            'id' => $report->id,
            'start_date' => $report->start_date->toDateString(),
            'end_date' => $report->end_date->toDateString(),
            'comparison_start_date' => $report->comparison_start_date->toDateString(),
            'comparison_end_date' => $report->comparison_end_date->toDateString(),
            'status' => $report->status,
            'progress_stage' => $report->progress_stage,
            'progress_percentage' => $report->progress_percentage,
            'metrics' => $report->metrics,
            'result' => $includeResult ? $report->result : null,
            'reused_evidence_count' => $report->reused_evidence_count,
            'new_evidence_count' => $report->new_evidence_count,
            'base_report_id' => $report->base_report_id,
            'requested_by' => $report->requestedBy?->name,
            'failure_message' => $report->failure_message,
            'created_at' => $report->created_at?->toISOString(),
            'completed_at' => $report->completed_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function buildContext(int $companyId, string $startDate, string $endDate): array
    {
        $range = $this->range($companyId, $startDate, $endDate);
        $newLeads = Lead::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$range['start_utc'], $range['end_utc']])
            ->with('owner:id,name')
            ->get();
        $newIds = $newLeads->pluck('id')->map(static fn ($id) => (int) $id)->all();

        $firstRescues = Message::query()
            ->where('company_id', $companyId)
            ->where('direction', 'outbound')
            ->where('is_rescue', true)
            ->whereBetween('sent_at', [$range['start_utc'], $range['end_utc']])
            ->whereNotIn('lead_id', $newIds ?: [0])
            ->selectRaw('lead_id, MIN(sent_at) as first_rescue_at')
            ->groupBy('lead_id')
            ->pluck('first_rescue_at', 'lead_id');
        $rescuedLeads = Lead::query()
            ->where('company_id', $companyId)
            ->where('created_at', '<', $range['start_utc'])
            ->whereIn('id', $firstRescues->keys())
            ->with('owner:id,name')
            ->get();
        $allLeads = $newLeads->concat($rescuedLeads);
        $leadIds = $allLeads->pluck('id')->all();

        $messages = Message::query()
            ->where('company_id', $companyId)
            ->whereIn('lead_id', $leadIds ?: [0])
            ->whereBetween('sent_at', [$range['start_utc'], $range['end_utc']])
            ->orderBy('sent_at')->orderBy('id')->get();
        $conversationIds = $messages->pluck('conversation_id')->unique()->all();
        $conversations = Conversation::query()->where('company_id', $companyId)
            ->whereIn('id', $conversationIds ?: [0])->with('owner:id,name')->get()->keyBy('id');
        $inputs = [];
        $consideredMessageIds = [];

        foreach ($allLeads as $lead) {
            $cohort = in_array((int) $lead->id, $newIds, true) ? 'new' : 'rescued';
            $evaluationStart = $cohort === 'new'
                ? Carbon::parse($lead->created_at)->max($range['start_utc'])
                : Carbon::parse((string) $firstRescues[(int) $lead->id]);
            $relevant = $messages->where('lead_id', $lead->id)
                ->filter(fn (Message $message): bool => $message->sent_at->betweenIncluded($evaluationStart, $range['end_utc']))
                ->values();

            if ($relevant->isEmpty() && $cohort === 'new') {
                $date = Carbon::parse($lead->created_at)->setTimezone($range['timezone'])->toDateString();
                $inputs[] = $this->evidenceInput($companyId, $lead, collect(), null, $cohort, $date, $range, $conversations);
                continue;
            }

            $groups = $relevant->groupBy(fn (Message $message): string => $message->sent_at->copy()->setTimezone($range['timezone'])->toDateString());
            $previousContext = Message::query()
                ->where('company_id', $companyId)->where('lead_id', $lead->id)
                ->where('sent_at', '<', $relevant->first()?->sent_at ?? $evaluationStart)
                ->latest('sent_at')->latest('id')->first();

            foreach ($groups as $date => $dayMessages) {
                foreach ($dayMessages as $message) {
                    $consideredMessageIds[(int) $message->id] = true;
                }
                $inputs[] = $this->evidenceInput($companyId, $lead, $dayMessages, $previousContext, $cohort, $date, $range, $conversations);
                $previousContext = $dayMessages->last();
            }
        }

        usort($inputs, static fn (array $a, array $b): int => [$a['analysis_date'], $a['lead_id']] <=> [$b['analysis_date'], $b['lead_id']]);
        $previousNewLeads = Lead::query()->where('company_id', $companyId)
            ->whereBetween('created_at', [$range['comparison_start_utc'], $range['comparison_end_utc']])->count();
        $currentNewLeads = $newLeads->count();
        $delta = $currentNewLeads - $previousNewLeads;
        $volume = [
            'current_new_leads' => $currentNewLeads,
            'previous_new_leads' => $previousNewLeads,
            'absolute_change' => $delta,
            'percentage_change' => $previousNewLeads > 0 ? round(($delta / $previousNewLeads) * 100, 1) : null,
            'trend' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'stable'),
        ];
        $rescueAttempts = $messages->where('is_rescue', true)->where('direction', 'outbound')->count();
        $rescuedResponses = $rescuedLeads->filter(function (Lead $lead) use ($messages, $firstRescues): bool {
            $first = Carbon::parse((string) $firstRescues[(int) $lead->id]);
            return $messages->where('lead_id', $lead->id)->contains(
                fn (Message $message): bool => $message->direction === 'inbound' && $message->sent_at->gt($first)
            );
        })->count();
        $metrics = [
            'volume' => $volume,
            'new_leads' => $currentNewLeads,
            'rescued_leads' => $rescuedLeads->count(),
            'rescue_attempts' => $rescueAttempts,
            'rescued_leads_with_response' => $rescuedResponses,
            'rescue_response_rate' => $rescuedLeads->count() > 0 ? round(($rescuedResponses / $rescuedLeads->count()) * 100, 1) : null,
            'conversations_considered' => $messages->whereIn('id', array_keys($consideredMessageIds))->pluck('conversation_id')->unique()->count(),
            'messages_considered' => count($consideredMessageIds),
        ];
        $fingerprint = hash('sha256', json_encode([
            'range' => $range['public'], 'metrics' => $metrics,
            'inputs' => array_map(static fn (array $input): array => [$input['cohort'], $input['input_hash']], $inputs),
            'schema' => 'campaign-intelligence-v1',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'range' => $range['public'],
            'metrics' => $metrics,
            'inputs' => $inputs,
            'fingerprint' => $fingerprint,
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceInput(int $companyId, Lead $lead, Collection $dayMessages, ?Message $contextMessage, string $cohort, string $date, array $range, Collection $conversations): array
    {
        $lastMessage = $dayMessages->last();
        $conversation = $lastMessage ? $conversations->get($lastMessage->conversation_id) : null;
        $ownerId = $conversation?->owner_user_id ?? $lead->owner_user_id;
        $ownerName = $conversation?->owner?->name ?? $lead->owner?->name;
        $dayEndUtc = Carbon::parse($date.' 23:59:59', $range['timezone'])->utc();
        $stageName = LeadStageHistory::query()
            ->where('lead_stage_histories.company_id', $companyId)
            ->where('lead_stage_histories.lead_id', $lead->id)
            ->where('lead_stage_histories.moved_at', '<=', $dayEndUtc)
            ->join('kanban_columns', 'kanban_columns.id', '=', 'lead_stage_histories.to_column_id')
            ->latest('lead_stage_histories.id')
            ->value('kanban_columns.name');
        $messagePayload = $dayMessages->map(static fn (Message $message): array => [
            'id' => (int) $message->id,
            'direction' => (string) $message->direction,
            'channel' => (string) $message->channel,
            'body' => $message->body,
            'audio_transcript' => $message->audio_transcript,
            'sent_at' => $message->sent_at?->toISOString(),
            'is_rescue' => (bool) $message->is_rescue,
            'context_only' => false,
        ])->values()->all();
        $contextPayload = $contextMessage ? [[
            'id' => (int) $contextMessage->id,
            'direction' => (string) $contextMessage->direction,
            'channel' => (string) $contextMessage->channel,
            'body' => $contextMessage->body,
            'audio_transcript' => $contextMessage->audio_transcript,
            'sent_at' => $contextMessage->sent_at?->toISOString(),
            'is_rescue' => (bool) $contextMessage->is_rescue,
            'context_only' => true,
        ]] : [];
        $allMessages = [...$contextPayload, ...$messagePayload];
        $hashPayload = [
            'lead_id' => (int) $lead->id, 'date' => $date, 'owner_id' => $ownerId,
            'stage_name' => $stageName, 'messages' => $allMessages,
        ];
        $inputHash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $metrics = [
            'message_count' => count($messagePayload),
            'inbound_count' => count(array_filter($messagePayload, static fn (array $message): bool => $message['direction'] === 'inbound')),
            'outbound_count' => count(array_filter($messagePayload, static fn (array $message): bool => $message['direction'] === 'outbound')),
            'rescue_attempts' => count(array_filter($messagePayload, static fn (array $message): bool => $message['is_rescue'])),
        ];

        return [
            'input_hash' => $inputHash,
            'cohort' => $cohort,
            'lead_id' => (int) $lead->id,
            'conversation_id' => $lastMessage?->conversation_id,
            'owner_user_id' => $ownerId,
            'owner_name' => $ownerName,
            'stage_name' => $stageName,
            'analysis_date' => $date,
            'metrics' => $metrics,
            'analyzer_payload' => [
                'key' => $inputHash,
                'analysis_date' => $date,
                'lead' => ['id' => (int) $lead->id, 'name' => $lead->name],
                'owner' => ['id' => $ownerId, 'name' => $ownerName],
                'stage_name' => $stageName,
                'messages' => $allMessages,
                'metrics' => $metrics,
            ],
        ];
    }

    private function persistEvidence(int $companyId, array $input, array $result): CampaignIntelligenceEvidence
    {
        $score = (int) ($result['score'] ?? -1);
        if ($score < 0 || $score > 100) {
            throw new InvalidArgumentException('O analisador retornou uma nota fora do intervalo de 0 a 100.');
        }

        return CampaignIntelligenceEvidence::query()->firstOrCreate([
            'company_id' => $companyId,
            'lead_id' => $input['lead_id'],
            'analysis_date' => $input['analysis_date'],
            'input_hash' => $input['input_hash'],
        ], [
            'conversation_id' => $input['conversation_id'],
            'owner_user_id' => $input['owner_user_id'],
            'owner_name' => $input['owner_name'],
            'stage_name' => $input['stage_name'],
            'score' => $score,
            'criteria_scores' => (array) ($result['criteria_scores'] ?? []),
            'summary' => (string) ($result['summary'] ?? ''),
            'positive_points' => array_values((array) ($result['positive_points'] ?? [])),
            'errors' => array_values((array) ($result['errors'] ?? [])),
            'improvement_suggestion' => $result['improvement_suggestion'] ?? null,
            ...$input['metrics'],
            'prompt_version' => (string) ($result['prompt_version'] ?? 'campaign-evidence-v1'),
            'model_provider' => $result['model_provider'] ?? null,
            'model_name' => $result['model_name'] ?? null,
            'analyzed_at' => now(),
        ]);
    }

    /** @param EloquentCollection<int, CampaignIntelligenceEvidence>|Collection<int, CampaignIntelligenceEvidence> $evidences @return array<string, mixed> */
    private function qualityRollup(Collection|EloquentCollection $evidences): array
    {
        if ($evidences->isEmpty()) {
            return ['lead_count' => 0, 'score' => null, 'verdict' => null, 'criteria_scores' => [], 'strengths' => [], 'improvements' => []];
        }
        $perLead = $evidences->groupBy('lead_id')->map(function (Collection $items): array {
            $criteriaKeys = $items->flatMap(fn (CampaignIntelligenceEvidence $item) => array_keys($item->criteria_scores ?? []))->unique();
            $criteria = $criteriaKeys->mapWithKeys(fn (string $key): array => [
                $key => round((float) $items->avg(fn (CampaignIntelligenceEvidence $item) => $item->criteria_scores[$key] ?? 0), 1),
            ])->all();
            return ['score' => (float) $items->avg('score'), 'criteria' => $criteria];
        });
        $score = round((float) $perLead->avg('score'), 1);
        $criteriaKeys = $perLead->flatMap(fn (array $item) => array_keys($item['criteria']))->unique();
        $criteria = $criteriaKeys->mapWithKeys(fn (string $key): array => [
            $key => round((float) $perLead->avg(fn (array $item) => $item['criteria'][$key] ?? 0), 1),
        ])->all();

        return [
            'lead_count' => $perLead->count(),
            'score' => $score,
            'verdict' => self::verdictForScore($score),
            'criteria_scores' => $criteria,
            'strengths' => $this->topStrings($evidences->flatMap(fn (CampaignIntelligenceEvidence $item) => $item->positive_points ?? [])),
            'improvements' => $this->topStrings($evidences->flatMap(fn (CampaignIntelligenceEvidence $item) => $item->errors ?? [])),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function teamRollup(EloquentCollection $evidences): array
    {
        return $evidences->groupBy(fn (CampaignIntelligenceEvidence $item): string => (string) ($item->owner_user_id ?? 0))
            ->map(function (Collection $items, string $ownerKey): array {
                $rollup = $this->qualityRollup($items);
                return [
                    'owner_user_id' => (int) $ownerKey ?: null,
                    'owner_name' => $items->first()?->owner_name ?? 'Sem responsável',
                    ...$rollup,
                ];
            })->sortByDesc('score')->values()->all();
    }

    private function existingEvidenceMap(int $companyId, array $inputs): Collection
    {
        $hashes = array_column($inputs, 'input_hash');
        return CampaignIntelligenceEvidence::query()->where('company_id', $companyId)
            ->whereIn('input_hash', $hashes ?: [''])->get()->keyBy('input_hash');
    }

    /** @return array<string, mixed> */
    private function range(int $companyId, string $startDate, string $endDate): array
    {
        $timezone = $this->settings->timezone($companyId);
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate, $timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Informe as datas no formato YYYY-MM-DD.');
        }
        if (!$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate) {
            throw new InvalidArgumentException('Informe datas válidas no formato YYYY-MM-DD.');
        }
        if ($start->gt($end)) {
            throw new InvalidArgumentException('A data inicial não pode ser posterior à data final.');
        }
        $days = $start->diffInDays($end) + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('O período máximo permitido é de 90 dias.');
        }
        if ($end->gte(CarbonImmutable::now($timezone)->startOfDay())) {
            throw new InvalidArgumentException('A data final deve ser, no máximo, ontem no fuso da empresa.');
        }
        $comparisonEnd = $start->subDay();
        $comparisonStart = $comparisonEnd->subDays($days - 1);

        return [
            'timezone' => $timezone,
            'start_utc' => Carbon::instance($start->startOfDay())->utc(),
            'end_utc' => Carbon::instance($end->endOfDay())->utc(),
            'comparison_start_utc' => Carbon::instance($comparisonStart->startOfDay())->utc(),
            'comparison_end_utc' => Carbon::instance($comparisonEnd->endOfDay())->utc(),
            'public' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'comparison_start_date' => $comparisonStart->toDateString(),
                'comparison_end_date' => $comparisonEnd->toDateString(),
                'days' => $days,
                'timezone' => $timezone,
            ],
        ];
    }

    private function progress(CampaignIntelligenceReport $report, string $stage, int $percentage, array $extra = []): void
    {
        $report->forceFill([...$extra, 'progress_stage' => $stage, 'progress_percentage' => $percentage])->save();
        $report->refresh();
    }

    private function normalizeVerdict(string $verdict): string
    {
        return in_array($verdict, ['good', 'needs_improvement', 'poor'], true) ? $verdict : 'needs_improvement';
    }

    public static function verdictForScore(float $score): string
    {
        return $score >= 70 ? 'good' : ($score >= 50 ? 'needs_improvement' : 'poor');
    }

    /** @return string[] */
    private function topStrings(Collection $values): array
    {
        return $values->filter(static fn ($value): bool => is_string($value))->map(fn (string $value): string => trim($value))->filter()
            ->countBy()->sortDesc()->keys()->take(5)->values()->all();
    }

    /** @return array<string, int> */
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
