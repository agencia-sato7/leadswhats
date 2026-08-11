<?php

return [
    'provider' => env('WHATSAPP_PROVIDER', 'meta_cloud'),
    'cloud_api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v21.0'),
    'cloud_webhook_verify_token' => env('WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN', ''),
    'cloud_app_secret' => env('WHATSAPP_CLOUD_APP_SECRET', ''),
    'meta_app_id' => env('META_APP_ID', ''),
    'meta_app_secret' => env('META_APP_SECRET', ''),
    'meta_redirect_uri' => env('META_REDIRECT_URI', ''),
    'meta_graph_api_version' => env('META_GRAPH_API_VERSION', 'v25.0'),
    'meta_connect_timeout_seconds' => env('META_HTTP_CONNECT_TIMEOUT_SECONDS', 5),
    'meta_timeout_seconds' => env('META_HTTP_TIMEOUT_SECONDS', 15),
];
