<?php

return [
    'api_url' => rtrim((string) env('INTSEC_API_URL', 'http://127.0.0.1:8000'), '/'),
    'api_token' => env('INTSEC_API_TOKEN'),
    'source' => env('INTSEC_SOURCE', 'hotel-booking'),
    'blocklist_cache_seconds' => (int) env('INTSEC_BLOCKLIST_CACHE_SECONDS', 60),
];
