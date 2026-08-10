<?php

namespace App\Data\Intelligence;

final readonly class ConversationAnalysisInput
{
    /**
     * @param array<int, array{id:int,direction:string,channel:string,body:?string,audio_transcript:?string,sent_at:string}> $messages
     * @param array<int, array{id:int,name:string,rule_prompt:?string}> $kanbanColumns
     * @param array{id:int,name:?string,phone:?string,source:?string,creative_id:?string,creative_url:?string,campaign_name:?string,metadata:array<string,mixed>} $leadMetadata
     */
    public function __construct(
        public int $companyId,
        public int $conversationId,
        public int $leadId,
        public array $leadMetadata,
        public string $transcript,
        public array $messages,
        public ?int $currentKanbanColumnId,
        public array $kanbanColumns,
    ) {
    }
}
