<?php

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Pipeline;
use App\Services\DemoBootstrapService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

Artisan::command('leadswhats:doctor', function () {
    $checks = [];

    $pushCheck = function (string $name, string $status, string $detail) use (&$checks): void {
        $checks[] = [
            'name' => $name,
            'status' => $status,
            'detail' => $detail,
        ];
    };

    $env = (string) config('app.env');
    $debug = (bool) config('app.debug');
    $provider = (string) config('whatsapp.provider', 'fake');
    $allowFakeInProduction = (bool) config('whatsapp.allow_fake_in_production', false);

    $pushCheck('APP_ENV', 'OK', $env);
    $pushCheck('APP_DEBUG', $debug ? 'WARN' : 'OK', $debug ? 'true' : 'false');
    $pushCheck('WHATSAPP_PROVIDER', $provider === 'fake' ? 'WARN' : 'OK', $provider);

    if ($env === 'production' && $provider === 'fake' && !$allowFakeInProduction) {
        $pushCheck('WHATSAPP_PROVIDER_POLICY', 'FAIL', 'Provider fake ativo em production sem allow explícito.');
    } else {
        $pushCheck('WHATSAPP_PROVIDER_POLICY', 'OK', 'Policy de provider válida para o ambiente.');
    }

    $appKey = (string) config('app.key');
    $pushCheck('APP_KEY', $appKey !== '' ? 'OK' : 'FAIL', $appKey !== '' ? 'configurada' : 'ausente');

    if (!Schema::hasTable('migrations')) {
        $pushCheck('MIGRATIONS', 'FAIL', 'Tabela migrations ausente.');
    } else {
        $hasMigrations = DB::table('migrations')->exists();
        $pushCheck('MIGRATIONS', $hasMigrations ? 'OK' : 'FAIL', $hasMigrations ? 'migrations registradas' : 'sem migrations registradas');
    }

    if (!Schema::hasTable('companies')) {
        $pushCheck('COMPANIES', 'FAIL', 'Tabela companies ausente.');
        $companyCount = 0;
    } else {
        $companyCount = Company::query()->count();
        $pushCheck('COMPANIES', $companyCount > 0 ? 'OK' : 'FAIL', 'total=' . $companyCount);
    }

    if (!Schema::hasTable('company_business_settings') || !Schema::hasTable('companies')) {
        $pushCheck('COMPANY_SETTINGS', 'FAIL', 'Estruturas necessárias ausentes.');
    } else {
        $settingsCount = CompanyBusinessSetting::query()->count();
        $missingSettings = max(0, $companyCount - $settingsCount);
        $pushCheck('COMPANY_SETTINGS', $missingSettings === 0 ? 'OK' : 'FAIL', 'settings=' . $settingsCount . '; empresas_sem_settings=' . $missingSettings);
    }

    if (!Schema::hasTable('pipelines')) {
        $pushCheck('DEFAULT_PIPELINE', 'FAIL', 'Tabela pipelines ausente.');
    } else {
        $defaultPipelineExists = Pipeline::query()->where('is_default', true)->exists();
        $pushCheck('DEFAULT_PIPELINE', $defaultPipelineExists ? 'OK' : 'FAIL', $defaultPipelineExists ? 'existe pipeline default' : 'nenhum pipeline default encontrado');
    }

    $openApiPath = storage_path('api-docs/openapi.json');
    $openApiExists = is_file($openApiPath);
    $pushCheck('OPENAPI_FILE', $openApiExists ? 'OK' : 'FAIL', $openApiExists ? $openApiPath : 'arquivo ausente');

    $hasFail = false;

    $this->newLine();
    $this->info('LEADSWHATS Doctor');
    foreach ($checks as $check) {
        $line = sprintf('[%s] %s - %s', $check['status'], $check['name'], $check['detail']);
        if ($check['status'] === 'FAIL') {
            $hasFail = true;
            $this->error($line);
        } elseif ($check['status'] === 'WARN') {
            $this->warn($line);
        } else {
            $this->line($line);
        }
    }

    $this->newLine();

    return $hasFail ? 1 : 0;
})->purpose('Valida readiness operacional e configuração crítica do ambiente');

