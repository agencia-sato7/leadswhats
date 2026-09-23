<?php

return [
    'provider' => env('WHATSAPP_PROVIDER', 'meta_cloud'),
    'cloud_api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v21.0'),
    'cloud_webhook_verify_token' => env('WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN', ''),
    'cloud_app_secret' => env('WHATSAPP_CLOUD_APP_SECRET', ''),
    'meta_app_id' => env('META_APP_ID', ''),
    'meta_app_secret' => env('META_APP_SECRET', ''),
    // Compatibilidade apenas para o doctor detectar configurações antigas.
    // O Embedded Signup de Coexistência nunca envia este valor à Meta.
    'legacy_meta_redirect_uri' => env('META_REDIRECT_URI', ''),
    'meta_graph_api_version' => env('META_GRAPH_API_VERSION', 'v25.0'),
    'meta_connect_timeout_seconds' => env('META_HTTP_CONNECT_TIMEOUT_SECONDS', 5),
    'meta_timeout_seconds' => env('META_HTTP_TIMEOUT_SECONDS', 15),

    /*
    |--------------------------------------------------------------------------
    | Coexistência (WhatsApp Business app user onboarding)
    |--------------------------------------------------------------------------
    |
    | A conexão da empresa é feita pelo Embedded Signup configurado com o
    | produto "WhatsApp Business app user onboarding" (Coexistência). O
    | feature_type e a versão do session logging ficam em config para permitir
    | ajuste sem alterar o código do frontend/backend.
    |
    */
    'coexistence_config_id' => env('META_COEXISTENCE_CONFIG_ID', '1088502166986777'),
    'coexistence_feature_type' => env('META_COEXISTENCE_FEATURE_TYPE', 'whatsapp_business_app_onboarding'),
    'coexistence_session_info_version' => env('META_COEXISTENCE_SESSION_INFO_VERSION', '3'),
    'coexistence_auto_sync' => env('META_COEXISTENCE_AUTO_SYNC', true),
    'coexistence_sync_types' => ['contacts', 'history'],
];
