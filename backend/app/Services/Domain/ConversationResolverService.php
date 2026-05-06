<?php

namespace App\Services\Domain;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use Carbon\Carbon;

class ConversationResolverService
{
    public function resolve(Company $company, Lead $lead, ?int $ownerUserId, Carbon $sentAt): Conversation
    {
        $conversation = Conversation::where('company_id', $company->id)
            ->where('lead_id', $lead->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'owner_user_id' => $ownerUserId ?? $lead->owner_user_id,
            'status' => 'active',
            'started_at' => $sentAt,
            'last_message_at' => $sentAt,
        ]);
    }
}
