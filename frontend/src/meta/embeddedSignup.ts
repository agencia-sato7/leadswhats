import type {
  CompleteWhatsAppEmbeddedSignupRequest,
  WhatsAppEmbeddedSignupData,
  WhatsAppEmbeddedSignupEvent,
} from '../types';

const FACEBOOK_SDK_ID = 'facebook-jssdk';
const FACEBOOK_SDK_URL = 'https://connect.facebook.net/pt_BR/sdk.js';
const FACEBOOK_MESSAGE_ORIGIN = 'https://www.facebook.com';
const META_GRAPH_API_VERSION = 'v25.0';

export const WHATSAPP_EMBEDDED_SIGNUP_EXTRAS = {
  version: 'v4',
  featureType: 'whatsapp_business_app',
} as const;

type FacebookLoginResponse = {
  authResponse?: {
    code?: string;
  };
};

export type FacebookLoginOptions = {
  config_id: string;
  response_type: 'code';
  override_default_response_type: true;
  extras: typeof WHATSAPP_EMBEDDED_SIGNUP_EXTRAS;
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

type RawEmbeddedSignupMessage = {
  type?: unknown;
  event?: unknown;
  data?: unknown;
};

function parseMessageData(value: unknown): RawEmbeddedSignupMessage | null {
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value) as unknown;
      return typeof parsed === 'object' && parsed !== null ? parsed as RawEmbeddedSignupMessage : null;
    } catch {
      return null;
    }
  }

  return typeof value === 'object' && value !== null ? value as RawEmbeddedSignupMessage : null;
}

function optionalId(value: unknown): string | undefined {
  return typeof value === 'string' && value.trim() ? value.trim() : undefined;
}

function idList(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.filter((item): item is string => typeof item === 'string' && Boolean(item.trim())).map((item) => item.trim());
}

function normalizeFinishEvent(message: RawEmbeddedSignupMessage): WhatsAppEmbeddedSignupEvent | null {
  if (message.type !== 'WA_EMBEDDED_SIGNUP' || message.event !== 'FINISH') return null;
  if (typeof message.data !== 'object' || message.data === null) return null;

  const data = message.data as Record<string, unknown>;
  const phoneNumberId = optionalId(data.phone_number_id);
  const wabaId = optionalId(data.waba_id);
  if (!phoneNumberId || !wabaId) return null;

  const normalized: WhatsAppEmbeddedSignupData = {
    phone_number_id: phoneNumberId,
    waba_id: wabaId,
    page_ids: idList(data.page_ids),
    catalog_ids: idList(data.catalog_ids),
    dataset_ids: idList(data.dataset_ids),
    instagram_account_ids: idList(data.instagram_account_ids),
  };
  const businessId = optionalId(data.business_id);
  if (businessId) normalized.business_id = businessId;

  return {
    data: normalized,
    type: 'WA_EMBEDDED_SIGNUP',
    event: 'FINISH',
  };
}

export type EmbeddedSignupFlowState = {
  active: boolean;
  hasCode: boolean;
  hasFinishEvent: boolean;
};

export class EmbeddedSignupFlowError extends Error {
  constructor(
    public readonly reason: 'cancelled' | 'incomplete' | 'sdk' | 'backend',
    message: string,
  ) {
    super(message);
    this.name = 'EmbeddedSignupFlowError';
  }
}

type EmbeddedSignupFlowOptions<TResult> = {
  appId: string;
  configId: string;
  complete(payload: CompleteWhatsAppEmbeddedSignupRequest): Promise<TResult>;
  loadSdk?: (appId: string) => Promise<FacebookSdk>;
  browserWindow?: Window;
};

export class WhatsAppEmbeddedSignupFlow<TResult> {
  private readonly browserWindow: Window;
  private readonly loadSdk: (appId: string) => Promise<FacebookSdk>;
  private active = false;
  private submitting = false;
  private code: string | null = null;
  private finishEvent: WhatsAppEmbeddedSignupEvent | null = null;
  private resolve: ((result: TResult) => void) | null = null;
  private reject: ((error: EmbeddedSignupFlowError) => void) | null = null;
  private disposed = false;

  constructor(private readonly options: EmbeddedSignupFlowOptions<TResult>) {
    this.browserWindow = options.browserWindow ?? window;
    this.loadSdk = options.loadSdk ?? loadFacebookSdk;
    this.browserWindow.addEventListener('message', this.handleMessage);
  }

  getState(): EmbeddedSignupFlowState {
    return {
      active: this.active,
      hasCode: Boolean(this.code),
      hasFinishEvent: Boolean(this.finishEvent),
    };
  }

  async start(): Promise<TResult> {
    if (this.disposed) {
      throw new EmbeddedSignupFlowError('cancelled', 'A conexão com a Meta foi interrompida.');
    }
    if (this.active) {
      throw new EmbeddedSignupFlowError('incomplete', 'Uma conexão com a Meta já está em andamento.');
    }

    this.clearTemporaryState();
    this.active = true;

    let sdk: FacebookSdk;
    try {
      sdk = await this.loadSdk(this.options.appId);
    } catch {
      this.active = false;
      throw new EmbeddedSignupFlowError('sdk', 'Não foi possível abrir a Meta. Tente novamente.');
    }

    if (this.disposed || !this.active) {
      throw new EmbeddedSignupFlowError('cancelled', 'A conexão com a Meta foi interrompida.');
    }

    return new Promise<TResult>((resolve, reject) => {
      this.resolve = resolve;
      this.reject = reject;

      try {
        sdk.login(this.handleLoginResponse, {
          config_id: this.options.configId,
          response_type: 'code',
          override_default_response_type: true,
          extras: WHATSAPP_EMBEDDED_SIGNUP_EXTRAS,
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

    if (message.event !== 'FINISH') return;
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

    const payload: CompleteWhatsAppEmbeddedSignupRequest = {
      code: this.code,
      embedded_signup: this.finishEvent,
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

  private fail(reason: EmbeddedSignupFlowError['reason'], message: string): void {
    const reject = this.reject;
    this.clearTemporaryState();
    reject?.(new EmbeddedSignupFlowError(reason, message));
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

export function resetFacebookSdkLoaderForTests(): void {
  sdkPromise = null;
  sdkInitialized = false;
}
