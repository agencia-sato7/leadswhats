import { afterEach, describe, expect, it, vi } from 'vitest';

import { updateWhatsAppSettings } from './api';

describe('updateWhatsAppSettings', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('sends the technical Meta credentials and only exposes the configured token flag', async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({
      data: {
        provider: 'meta_cloud',
        status: 'configured',
        phone_number: '+15551234567',
        phone_number_id: 'phone-123',
        business_account_id: 'waba-456',
        waba_id: 'waba-456',
        business_id: null,
        page_ids: [],
        catalog_ids: [],
        dataset_ids: [],
        instagram_account_ids: [],
        access_token_configured: true,
        webhook_verify_token_configured: true,
        connected_at: '2026-08-24T13:00:00Z',
        last_error: null,
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await updateWhatsAppSettings('session-token', {
      provider: 'meta_cloud',
      phone_number: '+15551234567',
      phone_number_id: 'phone-123',
      business_account_id: 'waba-456',
      access_token: 'temporary-meta-token',
    });

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringMatching(/\/settings\/whatsapp$/),
      expect.objectContaining({
        method: 'PUT',
        headers: expect.objectContaining({ Authorization: 'Bearer session-token' }),
      }),
    );
    const request = fetchMock.mock.calls[0]?.[1];
    if (!request) throw new Error('Expected request options.');
    expect(JSON.parse(String(request.body))).toEqual(expect.objectContaining({
      access_token: 'temporary-meta-token',
      phone_number_id: 'phone-123',
    }));
    expect(result.access_token_configured).toBe(true);
    expect(result).not.toHaveProperty('access_token');
  });
});
