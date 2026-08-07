<?php

namespace App\Services\Domain;

use App\Models\Company;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LidResolutionService
{
    /**
     * Tabelas com lead_id que precisam ser realocadas para o lead canônico
     * quando um LID é resolvido para um número que já tem lead próprio.
     */
    private const MERGEABLE_TABLES = [
        "conversations",
        "messages",
        "conversation_events",
        "lead_source_histories",
        "lead_stage_histories",
        "lead_creative_analyses",
    ];

    public function __construct(private readonly PhoneNormalizationService $phoneNormalizer)
    {
    }

    /**
     * Reconcilia um lead criado como "lid:..." (identidade ainda não resolvida
     * pelo WhatsApp) assim que o provedor descobre o número real por trás do LID.
     * Se já existir um lead com o número real, faz merge em vez de violar a
     * unicidade (company_id, phone_e164): o lead do número real vira o canônico
     * e o lead "lid:..." é absorvido nele.
     */
    public function resolve(Company $company, string $lid, string $phone): void
    {
        $lidDigits = preg_replace("/\\D+/", "", $lid);
        if ($lidDigits === "") {
            return;
        }

        $realPhone = $this->phoneNormalizer->normalize($phone);
        if (str_starts_with($realPhone, "lid:")) {
            return;
        }

        $lidLead = Lead::where("company_id", $company->id)
            ->where("phone_e164", "lid:{$lidDigits}")
            ->first();

        if (!$lidLead) {
            return;
        }

        $existingRealLead = Lead::where("company_id", $company->id)
            ->where("phone_e164", $realPhone)
            ->first();

        if (!$existingRealLead) {
            $lidLead->phone_e164 = $realPhone;
            $lidLead->save();
            return;
        }

        if ($existingRealLead->id === $lidLead->id) {
            return;
        }

        $this->merge($existingRealLead, $lidLead);
    }

    private function merge(Lead $canonical, Lead $duplicate): void
    {
        DB::transaction(function () use ($canonical, $duplicate) {
            foreach (self::MERGEABLE_TABLES as $table) {
                DB::table($table)->where("lead_id", $duplicate->id)->update(["lead_id" => $canonical->id]);
            }

            $canonical->first_inbound_at = $this->earliest($canonical->first_inbound_at, $duplicate->first_inbound_at);
            $canonical->last_inbound_at = $this->latest($canonical->last_inbound_at, $duplicate->last_inbound_at);
            $canonical->last_outbound_at = $this->latest($canonical->last_outbound_at, $duplicate->last_outbound_at);
            $canonical->is_repeat_lead = $canonical->is_repeat_lead || $duplicate->is_repeat_lead;
            $canonical->save();

            $duplicate->delete();
        });
    }

    private function earliest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if (!$a) {
            return $b;
        }
        if (!$b) {
            return $a;
        }
        return $a->lessThan($b) ? $a : $b;
    }

    private function latest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if (!$a) {
            return $b;
        }
        if (!$b) {
            return $a;
        }
        return $a->greaterThan($b) ? $a : $b;
    }
}
