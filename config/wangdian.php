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
    'express_map' => is_array($expressMap) ? $expressMap : [],
    'connect_timeout' => max(1, (int)env('wangdian_connect_timeout', 5)),
    'timeout' => max(5, (int)env('wangdian_timeout', 30)),
];
