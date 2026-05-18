<?php

use App\Services\DemoBootstrapService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('leadswhats:demo-bootstrap', function (DemoBootstrapService $demoBootstrapService) {
    $summary = $demoBootstrapService->run();

    $this->newLine();
    $this->info('LEADSWHATS demo bootstrap concluído.');
    $this->line('empresa ok: ' . ($summary['company_ok'] ? 'sim' : 'não'));
    $this->line('usuários ok: ' . ($summary['users_ok'] ? 'sim' : 'não'));
    $this->line('settings ok: ' . ($summary['settings_ok'] ? 'sim' : 'não'));
    $this->line('pipeline ok: ' . ($summary['pipeline_ok'] ? 'sim' : 'não'));
    $this->line('colunas ok: ' . ($summary['columns_ok'] ? 'sim' : 'não'));
    $this->line('webhook token configurado: ' . ($summary['webhook_token_configured'] ? 'sim' : 'não'));
    $this->line('webhook token (apenas demo/local): ****' . $summary['webhook_token_suffix']);
    $this->newLine();
})->purpose('Prepara de forma idempotente os dados mínimos do ambiente demo/local');
