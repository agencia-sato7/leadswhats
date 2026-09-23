// @vitest-environment jsdom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  CoexistenceFlowError,
  loadFacebookSdk,
  resetFacebookSdkLoaderForTests,
  WhatsAppCoexistenceFlow,
  type FacebookLoginOptions,
  type FacebookSdk,
} from './coexistenceSignup';
import type { CompleteWhatsAppCoexistenceRequest } from '../types';
import { ApiError } from '../api';

const coexistenceFinishMessage = {
  type: 'WA_EMBEDDED_SIGNUP',
  event: 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
  data: {
    phone_number_id: 'phone-123',
    waba_id: 'waba-456',
    business_id: 'business-789',
    page_ids: ['page-1'],
    catalog_ids: ['catalog-1'],
    dataset_ids: ['dataset-1'],
    instagram_account_ids: ['instagram-1'],
  },
};

function dispatchMetaMessage(data: unknown, origin = 'https://www.facebook.com'): void {
  window.dispatchEvent(new MessageEvent('message', { data, origin }));
}

function createFlow<TResult = { ok: boolean }>(
  complete = vi.fn(async () => ({ ok: true }) as TResult),
  extras?: Partial<FacebookLoginOptions['extras']>,
) {
  let loginCallback: ((response: { authResponse?: { code?: string } }) => void) | null = null;
  let loginOptions: FacebookLoginOptions | null = null;
  const sdk: FacebookSdk = {
    init: vi.fn(),
    login(callback, options) {
      loginCallback = callback;
      loginOptions = options;
    },
  };
  const loadSdk = vi.fn(async () => sdk);
  const flow = new WhatsAppCoexistenceFlow<TResult>({
    appId: 'public-app-id',
    configId: 'public-config-id',
    extras,
    complete,
    loadSdk,
  });

  return {
    flow,
    complete,
    getLoginCallback: () => loginCallback,
    getLoginOptions: () => loginOptions,
  };
}

async function beginFlow<TResult>(flow: WhatsAppCoexistenceFlow<TResult>): Promise<void> {
  void flow.start().catch(() => undefined);
  await vi.waitFor(() => expect(flow.getState().active).toBe(true));
  await Promise.resolve();
}

describe('Meta Facebook SDK', () => {
  beforeEach(() => {
    resetFacebookSdkLoaderForTests();
    document.getElementById('facebook-jssdk')?.remove();
    delete window.FB;
    delete window.fbAsyncInit;
  });

  afterEach(() => {
    document.getElementById('facebook-jssdk')?.remove();
    delete window.FB;
    delete window.fbAsyncInit;
    resetFacebookSdkLoaderForTests();
  });

  it('carrega e inicializa o SDK uma única vez', async () => {
    const first = loadFacebookSdk('public-app-id');
    const second = loadFacebookSdk('public-app-id');

    expect(document.querySelectorAll('#facebook-jssdk')).toHaveLength(1);

    const init = vi.fn();
    window.FB = { init, login: vi.fn() };
    window.fbAsyncInit?.();

    await expect(first).resolves.toBe(window.FB);
    await expect(second).resolves.toBe(window.FB);
    expect(init).toHaveBeenCalledTimes(1);
  });
});

describe('Fluxo de Coexistência do WhatsApp', () => {
  const activeFlows: Array<{ dispose(): void }> = [];

  afterEach(() => {
    for (const flow of activeFlows) flow.dispose();
    activeFlows.length = 0;
  });

  it('ignora evento de origem inválida', async () => {
    const fixture = createFlow();
    activeFlows.push(fixture.flow);
    await beginFlow(fixture.flow);

    dispatchMetaMessage(coexistenceFinishMessage, 'https://example.com');
    fixture.getLoginCallback()?.({ authResponse: { code: 'temporary-code' } });

    expect(fixture.complete).not.toHaveBeenCalled();
    expect(fixture.flow.getState().hasFinishEvent).toBe(false);
  });

  it('cancelamento encerra o fluxo sem chamar o backend', async () => {
    const fixture = createFlow();
    activeFlows.push(fixture.flow);
    const pending = fixture.flow.start();
    await vi.waitFor(() => expect(fixture.getLoginCallback()).not.toBeNull());

    dispatchMetaMessage({ type: 'WA_EMBEDDED_SIGNUP', event: 'CANCEL', data: {} });

    await expect(pending).rejects.toMatchObject({ reason: 'cancelled' });
    expect(fixture.complete).not.toHaveBeenCalled();
  });

  it('não chama o backend quando o FINISH não possui code', async () => {
    const fixture = createFlow();
    activeFlows.push(fixture.flow);
    const pending = fixture.flow.start();
    await vi.waitFor(() => expect(fixture.getLoginCallback()).not.toBeNull());

    dispatchMetaMessage(coexistenceFinishMessage);
    fixture.getLoginCallback()?.({ authResponse: {} });

    await expect(pending).rejects.toMatchObject({ reason: 'incomplete' });
    expect(fixture.complete).not.toHaveBeenCalled();
  });

  it('considera FINISH sem waba_id como incompleto', async () => {
    const fixture = createFlow();
    activeFlows.push(fixture.flow);
    const pending = fixture.flow.start();
    await vi.waitFor(() => expect(fixture.getLoginCallback()).not.toBeNull());

    dispatchMetaMessage({
      type: 'WA_EMBEDDED_SIGNUP',
      event: 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
      data: { phone_number_id: 'phone-123' },
    });

    await expect(pending).rejects.toMatchObject({ reason: 'incomplete' });
    expect(fixture.complete).not.toHaveBeenCalled();
  });

  it('correlaciona o FINISH de coexistência com o code e envia o payload ao Laravel', async () => {
    const complete = vi.fn(async (_payload: CompleteWhatsAppCoexistenceRequest) => ({ ok: true }));
    const fixture = createFlow<{ ok: boolean }>(complete);
    activeFlows.push(fixture.flow);
    const pending = fixture.flow.start();
    await vi.waitFor(() => expect(fixture.getLoginCallback()).not.toBeNull());

    dispatchMetaMessage(JSON.stringify(coexistenceFinishMessage));
    fixture.getLoginCallback()?.({ authResponse: { code: 'temporary-code' } });

    await expect(pending).resolves.toEqual({ ok: true });
    expect(complete).toHaveBeenCalledWith({
      code: 'temporary-code',
      coexistence: coexistenceFinishMessage,
    });
    expect(fixture.getLoginOptions()).toEqual({
      config_id: 'public-config-id',
      response_type: 'code',
      override_default_response_type: true,
      extras: {
        version: 'v4',
        setup: {},
        featureType: 'whatsapp_business_app_onboarding',
        sessionInfoVersion: '3',
      },
    });
  });

  it('permite sobrescrever os extras com os valores do backend', async () => {
    const fixture = createFlow(undefined, {
      featureType: 'whatsapp_business_app_onboarding',
      sessionInfoVersion: '5',
    });
    activeFlows.push(fixture.flow);
    await beginFlow(fixture.flow);

    expect(fixture.getLoginOptions()?.extras).toEqual({
      version: 'v4',
      setup: {},
      featureType: 'whatsapp_business_app_onboarding',
      sessionInfoVersion: '5',
    });
  });

  it('limpa o code e o evento temporários após sucesso', async () => {
    const fixture = createFlow();
    activeFlows.push(fixture.flow);
    const pending = fixture.flow.start();
    await vi.waitFor(() => expect(fixture.getLoginCallback()).not.toBeNull());

    fixture.getLoginCallback()?.({ authResponse: { code: 'temporary-code' } });
    dispatchMetaMessage(coexistenceFinishMessage);
    await pending;

    expect(fixture.flow.getState()).toEqual({
      active: false,
      hasCode: false,
      hasFinishEvent: false,
    });
  });

  it('preserva a mensagem segura devolvida pela API', async () => {
    const complete = vi.fn(async () => {
      throw new ApiError(
        'A Meta recusou a autorização por uma configuração de redirecionamento incompatível. Inicie uma nova conexão.',
        502,
        { code: 100, type: 'OAuthException', error_subcode: 36008 },
      );
    });
    const fixture = createFlow<{ ok: boolean }>(complete);
    activeFlows.push(fixture.flow);
    const pending = fixture.flow.start();
    await vi.waitFor(() => expect(fixture.getLoginCallback()).not.toBeNull());

    dispatchMetaMessage(coexistenceFinishMessage);
    fixture.getLoginCallback()?.({ authResponse: { code: 'temporary-code' } });

    await expect(pending).rejects.toMatchObject({
      reason: 'backend',
      message: 'A Meta recusou a autorização por uma configuração de redirecionamento incompatível. Inicie uma nova conexão.',
    });
  });

  it('não expõe detalhes sensíveis quando o backend falha', async () => {
    const complete = vi.fn(async () => {
      throw new Error('code=private-code access_token=private-token client_secret=private-secret');
    });
    const fixture = createFlow<{ ok: boolean }>(complete);
    activeFlows.push(fixture.flow);
    const pending = fixture.flow.start();
    await vi.waitFor(() => expect(fixture.getLoginCallback()).not.toBeNull());

    dispatchMetaMessage(coexistenceFinishMessage);
    fixture.getLoginCallback()?.({ authResponse: { code: 'temporary-code' } });

    let received: unknown;
    try {
      await pending;
    } catch (error) {
      received = error;
    }

    expect(received).toBeInstanceOf(CoexistenceFlowError);
    expect(String(received)).not.toContain('private-code');
    expect(String(received)).not.toContain('private-token');
    expect(String(received)).not.toContain('private-secret');
    expect(fixture.flow.getState()).toEqual({ active: false, hasCode: false, hasFinishEvent: false });
  });
});