<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\User;
use App\Services\CompanyWhatsAppIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppQrControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_marks_integration_as_configured_when_qr_service_returns_an_already_connected_session(): void
    {
        // O serviço de QR pode reconectar sozinho usando credenciais salvas em
        // disco e já devolver "connected" na primeira resposta de /api/sessions,
        // sem passar pelo fluxo normal de escanear o QR code.
        Http::fake([
            '*/api/sessions' => Http::response([
                'data' => [
                    'status' => 'connected',
                    'qr_code' => null,
                    'phone' => '5511988887777',
                ],
            ], 200),
        ]);

        $company = Company::create(['name' => 'Empresa Qr Start', 'slug' => 'empresa-qr-start']);
        $gestor = $this->createGestor($company->id, 'gestor.qrstart@test.local');
        $token = $this->login($gestor->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/whatsapp/qr/start')
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame('connected', $data['session_status']);
        $this->assertSame('configured', $data['status']);
        $this->assertSame('5511988887777', $data['baileys_phone']);
        $this->assertNotNull($data['connected_at']);

        $this->assertDatabaseHas('company_whatsapp_integrations', [
            'company_id' => $company->id,
            'session_status' => CompanyWhatsAppIntegrationService::SESSION_STATUS_CONNECTED,
            'status' => CompanyWhatsAppIntegrationService::STATUS_CONFIGURED,
        ]);
    }

    public function test_status_reconciles_a_previously_desynced_integration(): void
    {
        Http::fake([
            '*/api/sessions/*/status' => Http::response([
                'data' => [
                    'status' => 'connected',
                    'qr_code' => null,
                    'phone' => '5511977776666',
                ],
            ], 200),
        ]);

        $company = Company::create(['name' => 'Empresa Qr Status', 'slug' => 'empresa-qr-status']);

        // Estado desalinhado real: session_status já "connected" (tela do
        // WhatsApp mostra "Conectado"), mas status ainda "not_configured"
        // (bloqueio geral do app / dashboard mostra "Desconectado").
        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => 'meta_cloud',
            'integration_type' => CompanyWhatsAppIntegrationService::INTEGRATION_TYPE_BAILEYS_QR,
            'status' => CompanyWhatsAppIntegrationService::STATUS_NOT_CONFIGURED,
            'session_status' => CompanyWhatsAppIntegrationService::SESSION_STATUS_CONNECTED,
        ]);

        $gestor = $this->createGestor($company->id, 'gestor.qrstatus@test.local');
        $token = $this->login($gestor->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/whatsapp/qr/status')
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame('connected', $data['session_status']);
        $this->assertSame('configured', $data['status']);
        $this->assertSame('5511977776666', $data['baileys_phone']);
    }

    private function createGestor(int $companyId, string $email): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => 'Gestor Teste',
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);
    }

    private function login(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
            'device_name' => 'tests',
        ])->assertOk();

        return (string) $response->json('token');
    }
}
