<?php

return [
    'analyzer' => env('CONVERSATION_ANALYZER', 'unavailable'),
    'service_url' => env('AI_SERVICE_URL', 'http://ai-service:8001'),
    'timeout_seconds' => (int) env('AI_SERVICE_TIMEOUT_SECONDS', 90),
    'connect_timeout_seconds' => (int) env('AI_SERVICE_CONNECT_TIMEOUT_SECONDS', 5),
];

