<?php

return [
    'enabled' => filter_var(env('debao_enabled', false), FILTER_VALIDATE_BOOLEAN),
    'client_id' => trim((string)env('debao_client_id', '')),
    'keys' => (array)(json_decode((string)env('debao_hmac_keys', '{}'), true) ?: []),
    'clock_skew' => max(30, (int)env('debao_hmac_clock_skew', 300)),
    'nonce_ttl' => max(600, (int)env('debao_hmac_nonce_ttl', 600)),
    'challenge_url' => rtrim((string)env('debao_binding_challenge_url', ''), '/'),
    'confirmation_enabled' => filter_var(env('debao_binding_confirmation_enabled', false), FILTER_VALIDATE_BOOLEAN),
    'phone_fallback_enabled' => filter_var(env('debao_binding_phone_fallback_enabled', false), FILTER_VALIDATE_BOOLEAN),
    'session_ttl' => max(60, (int)env('debao_session_ttl', 300)),
    'payment_provider' => trim((string)env('debao_payment_provider', '')),
    'public_base_url' => rtrim((string)env('debao_public_base_url', 'https://shop.denlaw.cn'), '/'),
];
