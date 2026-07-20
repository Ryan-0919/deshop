<?php

$enabled = filter_var(env('oss_enabled', false), FILTER_VALIDATE_BOOLEAN);

return [
    'enabled' => $enabled,
    'access_key_id' => (string)env('oss_access_key_id', ''),
    'access_key_secret' => (string)env('oss_access_key_secret', ''),
    'bucket' => (string)env('oss_bucket', ''),
    'endpoint' => (string)env('oss_endpoint', ''),
    'public_url' => (string)env('oss_public_url', ''),
    'connect_timeout' => (int)env('oss_connect_timeout', 5),
    'timeout' => (int)env('oss_timeout', 120),
];

