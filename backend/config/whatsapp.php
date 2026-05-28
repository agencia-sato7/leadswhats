<?php

return [
    "provider" => env("WHATSAPP_PROVIDER", "dynamic"),
    "allow_fake_in_production" => (bool) env("WHATSAPP_ALLOW_FAKE_IN_PRODUCTION", false),
    "cloud_api_version" => env("WHATSAPP_CLOUD_API_VERSION", "v21.0"),
    "cloud_webhook_verify_token" => env("WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN", ""),
];
