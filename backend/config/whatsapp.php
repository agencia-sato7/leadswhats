<?php

return [
    "provider" => env("WHATSAPP_PROVIDER", "meta_cloud"),
    "cloud_api_version" => env("WHATSAPP_CLOUD_API_VERSION", "v21.0"),
    "cloud_webhook_verify_token" => env("WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN", ""),
    "cloud_app_secret" => env("WHATSAPP_CLOUD_APP_SECRET", ""),
];
