import { afterEach, describe, expect, it, vi } from 'vitest';

import { changePassword, completeWhatsAppCoexistence, createAdminUser, disconnectWhatsApp, requestWhatsAppCoexistenceSync } from './api';

const coexistenceSettings = {
  provider: 'meta_cloud',
  status: 'configured',
  connection_mode: 'coexistence',
  is_coexistence: true,
  coexistence_app_id: 'app-public-1',
  coexistence_config_id: 'config-public-1',
  coexistence_feature_type: 'whatsapp_business_app_onboarding',
  coexistence_session_info_version: '3',
  phone_number: '+15551234567',
  phone_number_id: 'phone-123',
  business_account_id: 'waba-456',
  waba_id: 'waba-456',
  business_id: null,
  page_ids: [],
  catalog_ids: [],
  dataset_ids: [],
  instagram_account_ids: [],
  coexistence_opted_in_at: '2026-09-17T12:00:00Z',
  history_sync_status: 'requested',
  contacts_sync_status: 'requested',
  token_expires_at: null,
  access_token_configured: true,
  webhook_verify_token_configured: true,
  connected_at: '2026-09-17T12:00:00Z',
  last_error: null,
};

function stubFetch() {
  const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(
    JSON.stringify({ data: coexistenceSettings }),
    { status: 200, headers: { 'Content-Type': 'application/json' } },
  ));
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

function readBody(fetchMock: ReturnType<typeof stubFetch>): Record<string, unknown> {
  const request = fetchMock.mock.calls[0]?.[1];
  if (!request) throw new Error('Expected request options.');

  return JSON.parse(String(request.body)) as Record<string, unknown>;
}

describe('Coexistência do WhatsApp (api)', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('conclui a conexão enviando o code e o payload de session logging', async () => {
    const fetchMock = stubFetch();

    const result = await completeWhatsAppCoexistence('session-token', {
      code: 'temporary-code',
      coexistence: {
        type: 'WA_EMBEDDED_SIGNUP',
        event: 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
        data: {
          phone_number_id: 'phone-123',
          waba_id: 'waba-456',
          page_ids: [],
          catalog_ids: [],
          dataset_ids: [],
          instagram_account_ids: [],
        },
      },
    });

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringMatching(/\/settings\/whatsapp\/coexistence\/complete$/),
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({ Authorization: 'Bearer session-token' }),
      }),
    );
    expect(readBody(fetchMock)).toEqual(expect.objectContaining({
      code: 'temporary-code',
      coexistence: expect.objectContaining({
        type: 'WA_EMBEDDED_SIGNUP',
        event: 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
        data: expect.objectContaining({ waba_id: 'waba-456', phone_number_id: 'phone-123' }),
      }),
    }));
    expect(result.is_coexistence).toBe(true);
    expect(result).not.toHaveProperty('access_token');
  });

  it('desconecta usando somente a sessão e retorna o estado desconectado', async () => {
    const data = { ...coexistenceSettings, status: 'not_configured', access_token_configured: false, phone_number_id: null };
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ data }), { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);
    await expect(disconnectWhatsApp('session-token')).resolves.toEqual(data);
    expect(fetchMock).toHaveBeenCalledWith(expect.stringMatching(/\/settings\/whatsapp\/disconnect$/),
      expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ Authorization: 'Bearer session-token' }) }));
  });

  it('propaga falha da desconexão sem retornar sucesso', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Falha na Meta' }), { status: 502 })));
    await expect(disconnectWhatsApp('session-token')).rejects.toThrow();
  });

  it('propaga a mensagem específica da API também em erros 5xx', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(
      JSON.stringify({ message: 'Não foi possível desconectar o aplicativo da conta WhatsApp na Meta.' }),
      { status: 502, headers: { 'Content-Type': 'application/json' } },
    )));
    await expect(disconnectWhatsApp('session-token')).rejects.toThrow(
      'Não foi possível desconectar o aplicativo da conta WhatsApp na Meta.',
    );
  });

  it('usa a mensagem genérica em 5xx quando a API não envia mensagem', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 500 })));
    await expect(disconnectWhatsApp('session-token')).rejects.toThrow('O servidor não conseguiu concluir esta solicitação');
  });

  it('solicita sincronização manual informando o sync_type', async () => {
    const fetchMock = stubFetch();

    const result = await requestWhatsAppCoexistenceSync('session-token', 'both');

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringMatching(/\/settings\/whatsapp\/coexistence\/sync$/),
      expect.objectContaining({ method: 'POST' }),
    );
    expect(readBody(fetchMock)).toEqual({ sync_type: 'both' });
    expect(result.history_sync_status).toBe('requested');
  });
});

describe('Usuários e acesso (api)', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('cria usuário de clínica com perfil e senha temporária', async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ data: { id: 10 } }), { status: 201 }));
    vi.stubGlobal('fetch', fetchMock);
    await createAdminUser('platform-token', { company_id: 2, access_profile_id: 7, name: 'Conector', email: 'conector@clinica.test', password: 'temporaria-123' });
    expect(fetchMock).toHaveBeenCalledWith(expect.stringMatching(/\/admin\/users$/), expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ Authorization: 'Bearer platform-token' }) }));
    expect(JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body))).toEqual(expect.objectContaining({ company_id: 2, access_profile_id: 7 }));
  });

  it('confirma a nova senha no fluxo obrigatório', async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ message: 'ok', user: {} }), { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);
    await changePassword('session-token', 'temporaria-123', 'nova-senha-123');
    expect(JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body))).toEqual({ current_password: 'temporaria-123', password: 'nova-senha-123', password_confirmation: 'nova-senha-123' });
  });
});
