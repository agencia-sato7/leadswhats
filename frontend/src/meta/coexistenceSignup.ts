import type {
  CompleteWhatsAppCoexistenceRequest,
  WhatsAppCoexistenceData,
  WhatsAppCoexistenceEvent,
  WhatsAppCoexistenceFinishEvent,
} from '../types';

const FACEBOOK_SDK_ID = 'facebook-jssdk';
const FACEBOOK_SDK_URL = 'https://connect.facebook.net/pt_BR/sdk.js';
const FACEBOOK_MESSAGE_ORIGIN = 'https://www.facebook.com';
const META_GRAPH_API_VERSION = 'v25.0';

/**
 * Extras do Embedded Signup configurado com o produto "WhatsApp Business app
 * user onboarding" (Coexistência). `featureType` e `sessionInfoVersion` podem
 * ser sobrescritos com os valores vindos do backend
 * (GET /api/v1/settings/whatsapp).
 */
export const WHATSAPP_COEXISTENCE_EXTRAS = {
  version: 'v4',
  setup: {},
  featureType: 'whatsapp_business_app_onboarding',
  sessionInfoVersion: '3',
} as const;

export type WhatsAppCoexistenceExtras = {
  version: string;
  setup: Record<string, never>;
  featureType: string;
  sessionInfoVersion: string;
};

export const COEXISTENCE_FINISH_EVENTS: readonly WhatsAppCoexistenceFinishEvent[] = [
  'FINISH',
  'FINISH_ONLY_WABA',
  'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
];

function buildExtras(overrides?: Partial<WhatsAppCoexistenceExtras>): WhatsAppCoexistenceExtras {
  return {
    version: overrides?.version ?? WHATSAPP_COEXISTENCE_EXTRAS.version,
    setup: overrides?.setup ?? {},
    featureType: overrides?.featureType ?? WHATSAPP_COEXISTENCE_EXTRAS.featureType,
    sessionInfoVersion: overrides?.sessionInfoVersion ?? WHATSAPP_COEXISTENCE_EXTRAS.sessionInfoVersion,
  };
}

type FacebookLoginResponse = {
  authResponse?: {
    code?: string;
  };
};

export type FacebookLoginOptions = {
  config_id: string;
  response_type: 'code';
  override_default_response_type: true;
  extras: WhatsAppCoexistenceExtras;
};

export type FacebookSdk = {
  init(options: {
    appId: string;
    autoLogAppEvents: boolean;
    xfbml: boolean;
    version: string;
  }): void;
  login(callback: (response: FacebookLoginResponse) => void, options: FacebookLoginOptions): void;
};

declare global {
  interface Window {
    FB?: FacebookSdk;
    fbAsyncInit?: () => void;
  }
}

let sdkPromise: Promise<FacebookSdk> | null = null;
let sdkInitialized = false;

function initializeSdk(appId: string): FacebookSdk {
  if (!window.FB) throw new Error('SDK da Meta indisponível.');

  if (!sdkInitialized) {
    window.FB.init({
      appId,
      autoLogAppEvents: true,
      xfbml: true,
      version: META_GRAPH_API_VERSION,
    });
    sdkInitialized = true;
  }

  return window.FB;
}

export function loadFacebookSdk(appId: string): Promise<FacebookSdk> {
  if (sdkPromise) return sdkPromise;

  sdkPromise = new Promise<FacebookSdk>((resolve, reject) => {
    if (window.FB) {
      resolve(initializeSdk(appId));
      return;
    }

    const previousAsyncInit = window.fbAsyncInit;
    window.fbAsyncInit = () => {
      previousAsyncInit?.();
      try {
        resolve(initializeSdk(appId));
      } catch {
        sdkPromise = null;
        reject(new Error('Não foi possível inicializar a conexão com a Meta.'));
      }
    };

    const existingScript = document.getElementById(FACEBOOK_SDK_ID) as HTMLScriptElement | null;
    if (existingScript) return;

    const script = document.createElement('script');
    script.id = FACEBOOK_SDK_ID;
    script.src = FACEBOOK_SDK_URL;
    script.async = true;
    script.defer = true;
    script.crossOrigin = 'anonymous';
    script.onerror = () => {
      sdkPromise = null;
      reject(new Error('Não foi possível carregar a conexão segura com a Meta.'));
    };
    document.head.appendChild(script);
  });

  return sdkPromise;
}

export function resetFacebookSdkLoaderForTests(): void {
  sdkPromise = null;
  sdkInitialized = false;
}

type RawCoexistenceMessage = {
  type?: unknown;
  event?: unknown;
  data?: unknown;
};

function parseMessageData(value: unknown): RawCoexistenceMessage | null {
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value) as unknown;
      return typeof parsed === 'object' && parsed !== null ? parsed as RawCoexistenceMessage : null;
    } catch {
      return null;
    }
  }

  return typeof value === 'object' && value !== null ? value as RawCoexistenceMessage : null;
}

function optionalId(value: unknown): string | undefined {
  return typeof value === 'string' && value.trim() ? value.trim() : undefined;
}

function idList(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.filter((item): item is string => typeof item === 'string' && Boolean(item.trim())).map((item) => item.trim());
}

function isFinishEvent(event: unknown): event is WhatsAppCoexistenceFinishEvent {
  return typeof event === 'string'
    && (COEXISTENCE_FINISH_EVENTS as readonly string[]).includes(event);
}

function normalizeFinishEvent(message: RawCoexistenceMessage): WhatsAppCoexistenceEvent | null {
  if (message.type !== 'WA_EMBEDDED_SIGNUP' || !isFinishEvent(message.event)) return null;
  if (typeof message.data !== 'object' || message.data === null) return null;

  const data = message.data as Record<string, unknown>;
  const phoneNumberId = optionalId(data.phone_number_id);
  const wabaId = optionalId(data.waba_id);
  if (!phoneNumberId || !wabaId) return null;

  const normalized: WhatsAppCoexistenceData = {
    phone_number_id: phoneNumberId,
    waba_id: wabaId,
    page_ids: idList(data.page_ids),
    catalog_ids: idList(data.catalog_ids),
    dataset_ids: idList(data.dataset_ids),
    instagram_account_ids: idList(data.instagram_account_ids),
  };

  const businessId = optionalId(data.business_id);
  if (businessId) normalized.business_id = businessId;

  const phoneNumber = optionalId(data.phone_number);
  if (phoneNumber) normalized.phone_number = phoneNumber;

  return {
    data: normalized,
    type: 'WA_EMBEDDED_SIGNUP',
    event: message.event,
  };
}

export type CoexistenceFlowState = {
  active: boolean;
  hasCode: boolean;
  hasFinishEvent: boolean;
};

export class CoexistenceFlowError extends Error {
  constructor(
    public readonly reason: 'cancelled' | 'incomplete' | 'sdk' | 'backend',
    message: string,
  ) {
    super(message);
    this.name = 'CoexistenceFlowError';
  }
}

type CoexistenceFlowOptions<TResult> = {
  appId: string;
  configId: string;
  extras?: Partial<WhatsAppCoexistenceExtras>;
  complete(payload: CompleteWhatsAppCoexistenceRequest): Promise<TResult>;
  loadSdk?: (appId: string) => Promise<FacebookSdk>;
  browserWindow?: Window;
};

export class WhatsAppCoexistenceFlow<TResult> {
  private readonly browserWindow: Window;
  private readonly loadSdk: (appId: string) => Promise<FacebookSdk>;
  private readonly extras: WhatsAppCoexistenceExtras;
  private active = false;
  private submitting = false;
  private code: string | null = null;
  private finishEvent: WhatsAppCoexistenceEvent | null = null;
  private resolve: ((result: TResult) => void) | null = null;
  private reject: ((error: CoexistenceFlowError) => void) | null = null;
  private disposed = false;

  constructor(private readonly options: CoexistenceFlowOptions<TResult>) {
    this.browserWindow = options.browserWindow ?? window;
    this.loadSdk = options.loadSdk ?? loadFacebookSdk;
    this.extras = buildExtras(options.extras);
    this.browserWindow.addEventListener('message', this.handleMessage);
  }

  getState(): CoexistenceFlowState {
    return {
      active: this.active,
      hasCode: Boolean(this.code),
      hasFinishEvent: Boolean(this.finishEvent),
    };
  }

  async start(): Promise<TResult> {
    if (this.disposed) {
      throw new CoexistenceFlowError('cancelled', 'A conexão com a Meta foi interrompida.');
    }
    if (this.active) {
      throw new CoexistenceFlowError('incomplete', 'Uma conexão com a Meta já está em andamento.');
    }

    this.clearTemporaryState();
    this.active = true;

    let sdk: FacebookSdk;
    try {
      sdk = await this.loadSdk(this.options.appId);
    } catch {
      this.active = false;
      throw new CoexistenceFlowError('sdk', 'Não foi possível abrir a Meta. Tente novamente.');
    }

    if (this.disposed || !this.active) {
      throw new CoexistenceFlowError('cancelled', 'A conexão com a Meta foi interrompida.');
    }

    return new Promise<TResult>((resolve, reject) => {
      this.resolve = resolve;
      this.reject = reject;

      try {
        sdk.login(this.handleLoginResponse, {
          config_id: this.options.configId,
          response_type: 'code',
          override_default_response_type: true,
          extras: this.extras,
        });
      } catch {
        this.fail('sdk', 'Não foi possível abrir a Meta. Tente novamente.');
      }
    });
  }

  dispose(): void {
    this.disposed = true;
    this.browserWindow.removeEventListener('message', this.handleMessage);
    if (this.active) {
      this.fail('cancelled', 'A conexão com a Meta foi interrompida.');
    } else {
      this.clearTemporaryState();
    }
  }

  private readonly handleMessage = (event: MessageEvent): void => {
    if (!this.active || event.origin !== FACEBOOK_MESSAGE_ORIGIN) return;
    const message = parseMessageData(event.data);
    if (!message || message.type !== 'WA_EMBEDDED_SIGNUP') return;

    if (message.event === 'CANCEL') {
      this.fail('cancelled', 'Conexão cancelada. Nenhuma alteração foi realizada.');
      return;
    }

    if (!isFinishEvent(message.event)) return;

    const finishEvent = normalizeFinishEvent(message);
    if (!finishEvent) {
      this.fail('incomplete', 'A Meta não retornou todos os dados necessários. Tente novamente.');
      return;
    }

    this.finishEvent = finishEvent;
    this.tryComplete();
  };

  private readonly handleLoginResponse = (response: FacebookLoginResponse): void => {
    if (!this.active) return;
    const code = response.authResponse?.code;
    if (typeof code !== 'string' || !code.trim()) {
      this.fail('incomplete', 'A Meta não concluiu a autorização. Tente novamente.');
      return;
    }

    this.code = code;
    this.tryComplete();
  };

  private tryComplete(): void {
    if (!this.active || this.submitting || !this.code || !this.finishEvent) return;
    this.submitting = true;

    const payload: CompleteWhatsAppCoexistenceRequest = {
      code: this.code,
      coexistence: this.finishEvent,
    };

    void this.options.complete(payload)
      .then((result) => {
        const resolve = this.resolve;
        this.clearTemporaryState();
        resolve?.(result);
      })
      .catch(() => {
        this.fail('backend', 'Não foi possível concluir a conexão com o WhatsApp. Tente novamente.');
      });
  }

  private fail(reason: CoexistenceFlowError['reason'], message: string): void {
    const reject = this.reject;
    this.clearTemporaryState();
    reject?.(new CoexistenceFlowError(reason, message));
  }

  private clearTemporaryState(): void {
    this.active = false;
    this.submitting = false;
    this.code = null;
    this.finishEvent = null;
    this.resolve = null;
    this.reject = null;
  }
}