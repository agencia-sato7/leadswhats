<?php

namespace App\Contracts\Intelligence;

interface DailyReportAnalyzer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array;
}
