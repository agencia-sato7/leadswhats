<?php

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Pipeline;
use App\Services\DemoBootstrapService;
use App\Services\DemoCampaignIntelligenceService;
use App\Services\Domain\DailyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
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
    $this->line('conversas demo: ' . $summary['demo_conversations_count']);
    $this->line('webhook token configurado: ' . ($summary['webhook_token_configured'] ? 'sim' : 'não'));
    $this->line('webhook token (apenas demo/local): ****' . $summary['webhook_token_suffix']);
    $this->newLine();
})->purpose('Prepara de forma idempotente os dados mínimos do ambiente demo/local');

Artisan::command('leadswhats:demo-campaign', function (DemoCampaignIntelligenceService $demoCampaignService) {
    $summary = $demoCampaignService->run();

    $this->newLine();
    $this->info('Campanha de demonstração preparada.');
    $this->line('empresa: '.$summary['company_name']);
    $this->line('relatório: #'.$summary['report_id']);
    $this->line('período: '.$summary['start_date'].' a '.$summary['end_date']);
    $this->line('status: '.$summary['status']);
    $this->line('novos leads: '.($summary['metrics']['new_leads'] ?? 0));
    $this->line('leads resgatados: '.($summary['metrics']['rescued_leads'] ?? 0));
    $this->newLine();
})->purpose('Cria uma campanha fechada e determinística para apresentações locais');

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
    $provider = (string) config('whatsapp.provider', 'meta_cloud');

    $pushCheck('APP_ENV', 'OK', $env);
    $pushCheck('APP_DEBUG', $debug ? 'WARN' : 'OK', $debug ? 'true' : 'false');
    $providerIsSupported = in_array($provider, ['fake', 'meta_cloud'], true);
    $pushCheck('WHATSAPP_PROVIDER', !$providerIsSupported ? 'FAIL' : ($provider === 'fake' ? 'WARN' : 'OK'), $provider);

    if (!$providerIsSupported) {
        $pushCheck('WHATSAPP_PROVIDER_POLICY', 'FAIL', 'Provider não suportado. Use fake ou meta_cloud.');
    } elseif ($env === 'production' && $provider !== 'meta_cloud') {
        $pushCheck('WHATSAPP_PROVIDER_POLICY', 'FAIL', 'Production exige obrigatoriamente o provider meta_cloud.');
    } else {
        $pushCheck('WHATSAPP_PROVIDER_POLICY', 'OK', 'Policy de provider válida para o ambiente.');
    }

    if ($provider === 'meta_cloud') {
        $metaVerifyToken = (string) config('whatsapp.cloud_webhook_verify_token');
        if ($metaVerifyToken === '') {
            $pushCheck('WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN', 'FAIL', 'Token de verificação global do webhook da Meta ausente (WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN).');
        } else {
            $pushCheck('WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN', 'OK', 'configurado');
        }

        $metaAppSecret = (string) config('whatsapp.cloud_app_secret');
        if ($metaAppSecret === '') {
            $pushCheck('WHATSAPP_CLOUD_APP_SECRET', 'FAIL', 'App secret da Meta ausente (WHATSAPP_CLOUD_APP_SECRET).');
        } else {
            $pushCheck('WHATSAPP_CLOUD_APP_SECRET', 'OK', 'configurado');
        }

        if (Schema::hasTable('company_whatsapp_integrations')) {
            $configuredCount = DB::table('company_whatsapp_integrations')
                ->where('provider', 'meta_cloud')
                ->where('status', 'configured')
                ->whereNotNull('access_token_encrypted')
                ->where('access_token_encrypted', '!=', '')
                ->whereNotNull('phone_number_id')
                ->where('phone_number_id', '!=', '')
                ->whereNotNull('business_account_id')
                ->where('business_account_id', '!=', '')
                ->count();
            if ($configuredCount === 0) {
                $pushCheck('META_CLOUD_INTEGRATIONS', 'WARN', 'Nenhuma empresa possui integração de WhatsApp configurada.');
            } else {
                $pushCheck('META_CLOUD_INTEGRATIONS', 'OK', 'total_configurado=' . $configuredCount);
            }
        } else {
            $pushCheck('META_CLOUD_INTEGRATIONS', 'FAIL', 'Tabela company_whatsapp_integrations ausente.');
        }
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

Artisan::command('leadswhats:daily-report {--company=} {--date=} {--send-time=18:30} {--force}', function (DailyReportService $dailyReportService) {
    $companyOption = $this->option('company');
    $dateOption = $this->option('date');
    $sendTime = (string) ($this->option('send-time') ?: DailyReportService::DEFAULT_SEND_TIME);

    if ($companyOption !== null && $companyOption !== '') {
        $company = Company::query()
            ->when(
                is_numeric($companyOption),
                fn ($query) => $query->whereKey((int) $companyOption),
                fn ($query) => $query->where('slug', $companyOption),
            )
            ->first();

        if ($company === null) {
            $this->error('Empresa não encontrada: '.$companyOption);

            return 1;
        }

        $report = $dailyReportService->generateForCompany(
            $company,
            $dateOption ? CarbonImmutable::parse($dateOption) : null,
            (bool) $this->option('force'),
        );

        if ($report === null) {
            $this->warn('Relatório de '.$company->name.' já foi enviado. Use --force para reenviar.');

            return 0;
        }

        $this->newLine();
        $this->info('Relatório diário processado.');
        $this->line('empresa: '.$company->name);
        $this->line('data: '.$report->report_date->toDateString());
        $this->line('status: '.$report->status);
        $this->line('destinatários: '.count($report->recipients ?? []));
        $this->line('veredito da IA: '.($report->overall_verdict ?? 'n/a'));

        if ($report->status !== 'sent') {
            $this->warn('Nada foi enviado (status: '.$report->status.').');

            if (filled($report->error_message)) {
                $this->line('motivo: '.$report->error_message);
            }
        }

        $this->newLine();

        // "skipped" (clínica sem e-mail cadastrado) não é erro de execução.
        return in_array($report->status, ['sent', 'skipped'], true) ? 0 : 1;
    }

    $summary = $dailyReportService->runScheduled($sendTime);

    $this->newLine();
    $this->info('Rodada de relatórios diários concluída (horário local '.$sendTime.').');
    $this->line('enviados: '.$summary['sent']);
    $this->line('ignorados (fuso/horário): '.$summary['skipped']);
    $this->line('ignorados (sem e-mail cadastrado): '.$summary['without_recipient']);
    $this->line('falhas: '.$summary['failed']);
    $this->newLine();

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Gera e envia o relatório diário de IA para o e-mail cadastrado de cada empresa');

Schedule::command('leadswhats:daily-report')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->timezone('UTC');
