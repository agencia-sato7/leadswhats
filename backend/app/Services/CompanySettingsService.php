<?php

namespace App\Services;

use App\Models\Company;

class CompanySettingsService
{
    private const DEFAULT_TIMEZONE = 'America/Sao_Paulo';
    private const DEFAULT_WORKDAY_START = '08:00:00';
    private const DEFAULT_WORKDAY_END = '18:00:00';
    private const DEFAULT_LUNCH_START = '12:00:00';
    private const DEFAULT_LUNCH_END = '13:00:00';
    private const DEFAULT_WORKING_DAYS = [1, 2, 3, 4, 5];
    private const DEFAULT_REPEATED_LEAD_WINDOW_DAYS = 90;
    private const DEFAULT_RESCUE_THRESHOLD_HOURS = 24;
    private const DEFAULT_FIRST_RESPONSE_SLA_MINUTES = 15;
    private const DEFAULT_FOLLOW_UP_SLA_HOURS = 24;
    private const DEFAULT_STALE_CONVERSATION_HOURS = 48;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * @return array<string, mixed>
     */
    public function getForCompany(int $companyId): array
    {
        if (isset($this->cache[$companyId])) {
            return $this->cache[$companyId];
        }

        $company = Company::with('businessSetting')->find($companyId);

        if (!$company) {
            return $this->cache[$companyId] = $this->defaults();
        }

        $settings = $company->businessSetting;

        if (!$settings) {
            return $this->cache[$companyId] = [
                'timezone' => $company->timezone ?: self::DEFAULT_TIMEZONE,
                'workday_start_time' => $this->normalizeTime($company->work_start, self::DEFAULT_WORKDAY_START),
                'workday_end_time' => $this->normalizeTime($company->work_end, self::DEFAULT_WORKDAY_END),
                'lunch_start_time' => $this->normalizeNullableTime($company->lunch_start, self::DEFAULT_LUNCH_START),
                'lunch_end_time' => $this->normalizeNullableTime($company->lunch_end, self::DEFAULT_LUNCH_END),
                'working_days' => self::DEFAULT_WORKING_DAYS,
                'repeated_lead_window_days' => self::DEFAULT_REPEATED_LEAD_WINDOW_DAYS,
                'rescue_threshold_hours' => self::DEFAULT_RESCUE_THRESHOLD_HOURS,
                'first_response_sla_minutes' => self::DEFAULT_FIRST_RESPONSE_SLA_MINUTES,
                'follow_up_sla_hours' => self::DEFAULT_FOLLOW_UP_SLA_HOURS,
                'stale_conversation_hours' => self::DEFAULT_STALE_CONVERSATION_HOURS,
                'webhook_token' => null,
            ];
        }

        return $this->cache[$companyId] = [
            'timezone' => $settings->timezone ?: ($company->timezone ?: self::DEFAULT_TIMEZONE),
            'workday_start_time' => $this->normalizeTime($settings->workday_start_time, self::DEFAULT_WORKDAY_START),
            'workday_end_time' => $this->normalizeTime($settings->workday_end_time, self::DEFAULT_WORKDAY_END),
            'lunch_start_time' => $this->normalizeNullableTime($settings->lunch_start_time, self::DEFAULT_LUNCH_START),
            'lunch_end_time' => $this->normalizeNullableTime($settings->lunch_end_time, self::DEFAULT_LUNCH_END),
            'working_days' => is_array($settings->working_days) && $settings->working_days !== []
                ? array_values($settings->working_days)
                : self::DEFAULT_WORKING_DAYS,
            'repeated_lead_window_days' => (int) ($settings->repeated_lead_window_days ?: self::DEFAULT_REPEATED_LEAD_WINDOW_DAYS),
            'rescue_threshold_hours' => (int) ($settings->rescue_threshold_hours ?: self::DEFAULT_RESCUE_THRESHOLD_HOURS),
            'first_response_sla_minutes' => (int) ($settings->first_response_sla_minutes ?: self::DEFAULT_FIRST_RESPONSE_SLA_MINUTES),
            'follow_up_sla_hours' => (int) ($settings->follow_up_sla_hours ?: self::DEFAULT_FOLLOW_UP_SLA_HOURS),
            'stale_conversation_hours' => (int) ($settings->stale_conversation_hours ?: self::DEFAULT_STALE_CONVERSATION_HOURS),
            'webhook_token' => $settings->webhook_token,
        ];
    }

    public function timezone(int $companyId): string
    {
        return (string) $this->getForCompany($companyId)['timezone'];
    }

    /**
     * @return int[]
     */
    public function workingDays(int $companyId): array
    {
        return (array) $this->getForCompany($companyId)['working_days'];
    }

    public function rescueThresholdHours(int $companyId): int
    {
        return (int) $this->getForCompany($companyId)['rescue_threshold_hours'];
    }

    public function repeatedLeadWindowDays(int $companyId): int
    {
        return (int) $this->getForCompany($companyId)['repeated_lead_window_days'];
    }

    public function firstResponseSlaMinutes(int $companyId): int
    {
        return (int) $this->getForCompany($companyId)['first_response_sla_minutes'];
    }

    public function followUpSlaHours(int $companyId): int
    {
        return (int) $this->getForCompany($companyId)['follow_up_sla_hours'];
    }

    public function staleConversationHours(int $companyId): int
    {
        return (int) $this->getForCompany($companyId)['stale_conversation_hours'];
    }

    public function workdayStartTime(int $companyId): string
    {
        return (string) $this->getForCompany($companyId)['workday_start_time'];
    }

    public function workdayEndTime(int $companyId): string
    {
        return (string) $this->getForCompany($companyId)['workday_end_time'];
    }

    public function lunchStartTime(int $companyId): ?string
    {
        return $this->getForCompany($companyId)['lunch_start_time'];
    }

    public function lunchEndTime(int $companyId): ?string
    {
        return $this->getForCompany($companyId)['lunch_end_time'];
    }

    public function webhookToken(int $companyId): ?string
    {
        return $this->getForCompany($companyId)['webhook_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'timezone' => self::DEFAULT_TIMEZONE,
            'workday_start_time' => self::DEFAULT_WORKDAY_START,
            'workday_end_time' => self::DEFAULT_WORKDAY_END,
            'lunch_start_time' => self::DEFAULT_LUNCH_START,
            'lunch_end_time' => self::DEFAULT_LUNCH_END,
            'working_days' => self::DEFAULT_WORKING_DAYS,
            'repeated_lead_window_days' => self::DEFAULT_REPEATED_LEAD_WINDOW_DAYS,
            'rescue_threshold_hours' => self::DEFAULT_RESCUE_THRESHOLD_HOURS,
            'first_response_sla_minutes' => self::DEFAULT_FIRST_RESPONSE_SLA_MINUTES,
            'follow_up_sla_hours' => self::DEFAULT_FOLLOW_UP_SLA_HOURS,
            'stale_conversation_hours' => self::DEFAULT_STALE_CONVERSATION_HOURS,
            'webhook_token' => null,
        ];
    }

    private function normalizeTime(?string $value, string $fallback): string
    {
        if (!$value) {
            return $fallback;
        }

        if (strlen($value) === 5) {
            return $value . ':00';
        }

        return $value;
    }

    private function normalizeNullableTime(?string $value, ?string $fallback): ?string
    {
        if (!$value) {
            return $fallback;
        }

        if (strlen($value) === 5) {
            return $value . ':00';
        }

        return $value;
    }
}
