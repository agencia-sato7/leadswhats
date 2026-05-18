<?php

return [
    "provider" => env("WHATSAPP_PROVIDER", "fake"),
    "allow_fake_in_production" => (bool) env("WHATSAPP_ALLOW_FAKE_IN_PRODUCTION", false),
];
