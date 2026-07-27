<?php

$environment = strtolower(trim((string)env('wangdian_environment', 'sandbox')));
$defaultBaseUrl = $environment === 'production'
    ? 'https://api.wangdian.cn/openapi2'
    : 'https://sandbox.wangdian.cn/openapi2';

$syncFrom = trim((string)env('wangdian_order_sync_from', ''));
if($syncFrom !== '' && !ctype_digit($syncFrom))
{
    $parsedSyncFrom = strtotime($syncFrom);
    $syncFrom = $parsedSyncFrom === false ? '0' : (string)$parsedSyncFrom;
}

$expressMap = json_decode((string)env('wangdian_express_map', '{}'), true);
$cronAllowedIps = preg_split('/[\s,]+/', trim((string)env('wangdian_cron_allowed_ips', '127.0.0.1,::1'))) ?: [];

return [
    'enabled' => filter_var(env('wangdian_enabled', false), FILTER_VALIDATE_BOOLEAN),
    'environment' => $environment,
    'base_url' => rtrim((string)env('wangdian_base_url', $defaultBaseUrl), '/'),
    'sid' => trim((string)env('wangdian_sid', '')),
    'appkey' => trim((string)env('wangdian_appkey', '')),
    'appsecret' => (string)env('wangdian_appsecret', ''),
    'shop_no' => trim((string)env('wangdian_shop_no', '')),
    'order_sync_from' => (int)$syncFrom,
    'shopxo_warehouse_id' => max(0, (int)env('wangdian_shopxo_warehouse_id', 0)),
    'order_batch_size' => min(50, max(1, (int)env('wangdian_order_batch_size', 50))),
    'logistics_batch_size' => min(100, max(1, (int)env('wangdian_logistics_batch_size', 100))),
    'inventory_batch_size' => min(2000, max(100, (int)env('wangdian_inventory_batch_size', 100))),
    'aftersale_batch_size' => min(10, max(1, (int)env('wangdian_aftersale_batch_size', 10))),
    'aftersale_query_lookback' => min(2592000, max(300, (int)env('wangdian_aftersale_query_lookback', 604800))),
    'aftersale_auto_process' => filter_var(env('wangdian_aftersale_auto_process', false), FILTER_VALIDATE_BOOLEAN),
    'aftersale_refundment' => min(2, max(0, (int)env('wangdian_aftersale_refundment', 0))),
    'cron_token' => trim((string)env('wangdian_cron_token', '')),
    'cron_allowed_ips' => array_values(array_filter(array_map('trim', $cronAllowedIps), static fn(string $ip): bool => $ip !== '')),
    'express_map' => is_array($expressMap) ? $expressMap : [],
    'connect_timeout' => max(1, (int)env('wangdian_connect_timeout', 5)),
    'timeout' => max(5, (int)env('wangdian_timeout', 30)),
];
