<?php

namespace Tests\Feature;

use App\Mail\DailyReportMail;
use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\DailyReport;
use App\Models\Lead;
use App\Models\User;
use App\Services\Domain\DailyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DailyReportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Alinhado aos demais testes de inteligência: o analisador determinístico
        // garante que a suíte não dependa do serviço de IA nem da OPENAI_API_KEY.
        config()->set('intelligence.analyzer', 'fake');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function makeCompany(string $slug, string $timezone = 'America/Sao_Paulo', ?string $recipient = null): Company
    {
        $company = Company::create([
            'name' => 'Clínica '.$slug,
            'slug' => $slug,
            'timezone' => $timezone,
            'active' => true,
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'timezone' => $timezone,
            'working_days' => [1, 2, 3, 4, 5],
            'daily_report_recipient' => $recipient,
        ]);

        return $company;
    }

    private function makeUser(Company $company, string $email, string $role): User
    {
        return User::create([
            'company_id' => $company->id,
            'name' => ucfirst($role).' '.$company->slug,
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => $role,
            'active' => true,
        ]);
    }

    private function makeLead(Company $company, string $phone, bool $repeat = false): Lead
    {
        return Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead '.$phone,
            'phone_e164' => $phone,
            'source' => 'desconhecido',
            'source_method' => 'auto',
            'is_repeat_lead' => $repeat,
        ]);
    }

    public function test_command_sends_the_daily_report_only_to_the_configured_recipient(): void
    {
        $recipient = 'relatorios@clinica-a.local';
        $company = $this->makeCompany('clinica-a', 'America/Sao_Paulo', $recipient);
        $admin = $this->makeUser($company, 'admin@clinica-a.local', 'admin');
        $gestor = $this->makeUser($company, 'gestor@clinica-a.local', 'gestor');
        $sdr = $this->makeUser($company, 'sdr@clinica-a.local', 'sdr');
        $this->makeLead($company, '+5511900000001');
        $this->makeLead($company, '+5511900000002');
        $this->makeLead($company, '+5511900000003', true);

        Mail::fake();

        $this->artisan('leadswhats:daily-report', ['--company' => $company->slug])
            ->expectsOutputToContain('Relatório diário processado.')
            ->assertExitCode(0);

        $report = DailyReport::query()->sole();
        $this->assertSame('sent', $report->status);
        $this->assertSame(2, $report->metrics['new_leads_today']);
        $this->assertSame(1, $report->metrics['repeat_leads_today']);
        $this->assertSame([$recipient], $report->recipients);
        $this->assertSame('needs_improvement', $report->overall_verdict);
        $this->assertStringContainsString('2 leads novos', (string) $report->executive_summary);
        $this->assertNotNull($report->sent_at);

        Mail::assertQueued(DailyReportMail::class, 1);
        Mail::assertQueued(DailyReportMail::class, function (DailyReportMail $mail) use ($recipient, $admin, $gestor, $sdr, $company): bool {
            // O relatório precisa existir no banco para sobreviver à fila
            // (SerializesModels), então simulamos o round-trip de serialização.
            $queued = unserialize(serialize($mail));
            $html = $queued->render();

            return $queued->report->is($mail->report)
                && $mail->hasTo($recipient)
                && ! $mail->hasTo($admin->email)
                && ! $mail->hasTo($gestor->email)
                && ! $mail->hasTo($sdr->email)
                && str_contains($html, $company->name)
                && str_contains($html, 'Relatório diário de performance')
                && str_contains($html, 'Novos leads hoje');
        });
    }

    public function test_command_is_idempotent_for_the_same_company_and_day_and_supports_force(): void
    {
        $company = $this->makeCompany('clinica-b', 'America/Sao_Paulo', 'relatorios@clinica-b.local');
        $this->makeUser($company, 'admin@clinica-b.local', 'admin');
        $this->makeLead($company, '+5511900000011');

        Mail::fake();

        $this->artisan('leadswhats:daily-report', ['--company' => $company->slug])->assertExitCode(0);
        $this->artisan('leadswhats:daily-report', ['--company' => $company->slug])
            ->expectsOutputToContain('já foi enviado')
            ->assertExitCode(0);

        $this->assertSame(1, DailyReport::query()->count());
        Mail::assertQueued(DailyReportMail::class, 1);

        $this->artisan('leadswhats:daily-report', ['--company' => $company->slug, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(1, DailyReport::query()->count());
        Mail::assertQueued(DailyReportMail::class, 2);
    }

    public function test_command_skips_the_report_when_the_clinic_has_no_configured_recipient(): void
    {
        $company = $this->makeCompany('clinica-sem-destinatario');
        $this->makeUser($company, 'admin@clinica-sem-destinatario.local', 'admin');
        $this->makeLead($company, '+5511900000051');

        Mail::fake();

        // Nem o usuário admin da clínica vira destinatário: só o e-mail do
        // cadastro (Central da Agência) conta para o envio.
        $this->artisan('leadswhats:daily-report', ['--company' => $company->slug])
            ->expectsOutputToContain('status: skipped')
            ->expectsOutputToContain(DailyReportService::SKIP_REASON_NO_RECIPIENT)
            ->assertExitCode(0);

        $report = DailyReport::query()->sole();
        $this->assertSame('skipped', $report->status);
        $this->assertSame([], $report->recipients);
        $this->assertNull($report->report_payload); // a IA não foi chamada
        $this->assertNull($report->sent_at);
        Mail::assertNotQueued(DailyReportMail::class);

        // Cadastrando o e-mail depois, no mesmo dia, o relatório é gerado e
        // enviado (o curto-circuito de idempotência vale apenas para "sent").
        CompanyBusinessSetting::query()
            ->where('company_id', $company->id)
            ->update(['daily_report_recipient' => 'destinatario@clinica-sem-destinatario.local']);

        $this->artisan('leadswhats:daily-report', ['--company' => $company->slug])
            ->assertExitCode(0);

        $report->refresh();
        $this->assertSame('sent', $report->status);
        $this->assertSame(['destinatario@clinica-sem-destinatario.local'], $report->recipients);
        Mail::assertQueued(DailyReportMail::class, 1);
    }

    public function test_scheduled_run_only_sends_the_report_in_the_company_local_time(): void
    {
        $saoPaulo = $this->makeCompany('clinica-sp', 'America/Sao_Paulo', 'relatorios@clinica-sp.local');
        $spAdmin = $this->makeUser($saoPaulo, 'admin@clinica-sp.local', 'admin');
        $this->makeLead($saoPaulo, '+5511900000021');

        $tokyo = $this->makeCompany('clinica-tokyo', 'Asia/Tokyo', 'relatorios@clinica-tokyo.local');
        $tokyoAdmin = $this->makeUser($tokyo, 'admin@clinica-tokyo.local', 'admin');
        $this->makeLead($tokyo, '+819000000022');

        Mail::fake();

        // 18:30 em São Paulo (UTC-3) é 06:30 do dia seguinte em Tóquio (UTC+9).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 21:30:00', 'UTC'));

        $this->artisan('leadswhats:daily-report')
            ->expectsOutputToContain('enviados: 1')
            ->expectsOutputToContain('ignorados (fuso/horário): 1')
            ->assertExitCode(0);

        $spReport = DailyReport::query()->where('company_id', $saoPaulo->id)->sole();
        $this->assertSame('2026-09-23', $spReport->report_date->toDateString());
        $this->assertSame('sent', $spReport->status);
        $this->assertSame(0, DailyReport::query()->where('company_id', $tokyo->id)->count());

        Mail::assertQueued(DailyReportMail::class, function (DailyReportMail $mail): bool {
            return $mail->hasTo('relatorios@clinica-sp.local') && ! $mail->hasTo('relatorios@clinica-tokyo.local');
        });

        // 18:30 em Tóquio é 06:30 do mesmo dia em São Paulo.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 09:30:00', 'UTC'));

        $this->artisan('leadswhats:daily-report')
            ->expectsOutputToContain('enviados: 1')
            ->assertExitCode(0);

        $tokyoReport = DailyReport::query()->where('company_id', $tokyo->id)->sole();
        $this->assertSame('2026-09-24', $tokyoReport->report_date->toDateString());
        $this->assertSame('sent', $tokyoReport->status);
        $this->assertSame(2, DailyReport::query()->count());
    }

    public function test_report_keeps_each_company_data_isolated(): void
    {
        $companyA = $this->makeCompany('clinica-isolada-a', 'America/Sao_Paulo', 'relatorios@clinica-isolada-a.local');
        $this->makeUser($companyA, 'admin@clinica-isolada-a.local', 'admin');
        $this->makeLead($companyA, '+5511900000031');
        $this->makeLead($companyA, '+5511900000032');

        $companyB = $this->makeCompany('clinica-isolada-b', 'America/Sao_Paulo', 'relatorios@clinica-isolada-b.local');
        $bAdmin = $this->makeUser($companyB, 'admin@clinica-isolada-b.local', 'admin');

        foreach (['+5511900000041', '+5511900000042', '+5511900000043', '+5511900000044', '+5511900000045'] as $phone) {
            $this->makeLead($companyB, $phone);
        }

        Mail::fake();

        $this->artisan('leadswhats:daily-report', ['--company' => 'clinica-isolada-a'])->assertExitCode(0);

        $reportA = DailyReport::query()->where('company_id', $companyA->id)->sole();
        $this->assertSame(2, $reportA->metrics['new_leads_today']);
        $this->assertSame(2, $reportA->metrics['unassigned_leads']);
        $this->assertNull(DailyReport::query()->where('company_id', $companyB->id)->first());
        Mail::assertNotQueued(DailyReportMail::class, fn (DailyReportMail $mail): bool => $mail->hasTo($bAdmin->email) || $mail->hasTo('relatorios@clinica-isolada-b.local'));

        $this->artisan('leadswhats:daily-report', ['--company' => 'clinica-isolada-b'])->assertExitCode(0);

        $reportB = DailyReport::query()->where('company_id', $companyB->id)->sole();
        $this->assertSame(5, $reportB->metrics['new_leads_today']);
        Mail::assertQueued(DailyReportMail::class, 2);
        Mail::assertQueued(DailyReportMail::class, fn (DailyReportMail $mail): bool => $mail->hasTo('relatorios@clinica-isolada-a.local'));
        Mail::assertQueued(DailyReportMail::class, fn (DailyReportMail $mail): bool => $mail->hasTo('relatorios@clinica-isolada-b.local') && ! $mail->hasTo($bAdmin->email));
    }

    public function test_report_includes_the_ai_quality_scores_of_the_day(): void
    {
        $this->artisan('leadswhats:demo-bootstrap')->assertExitCode(0);

        $company = Company::query()->where('slug', 'empresa-demo')->sole();

        Mail::fake();

        $this->artisan('leadswhats:daily-report', ['--company' => 'empresa-demo'])->assertExitCode(0);

        $report = DailyReport::query()->where('company_id', $company->id)->sole();
        $this->assertSame(75.5, $report->quality['average_score']);
        $this->assertGreaterThan(0, $report->quality['analyzed_conversations']);
        $this->assertSame(2, $report->quality['low_quality_conversations']);
        $this->assertIsNumeric($report->quality['criteria_averages']['acolhimento']);
        $this->assertSame('fake', $report->report_payload['model_provider']);

        Mail::assertQueued(DailyReportMail::class, function (DailyReportMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, '75.5')
                && str_contains($html, 'Conversas avaliadas')
                && str_contains($html, 'Descoberta da necessidade');
        });
    }
}
