<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianStore;
use app\integration\wangdian\WangDianStoreInterface;
use app\integration\wangdian\WangDianSyncService;

$store = new WangDianStore();
if(!$store instanceof WangDianStoreInterface)
{
    throw new RuntimeException('The composed Wangdiantong store must retain its public contract.');
}
foreach([
    'pendingOrders', 'pendingAftersales', 'startAttempt', 'markSuccess', 'markFailure',
    'applyLogistics', 'applyInventory', 'applyAftersaleStatus',
] as $method)
{
    if(!method_exists($store, $method))
    {
        throw new RuntimeException('The composed Wangdiantong store is missing '.$method.'.');
    }
}

$service = new WangDianSyncService(['enabled'=>false]);
foreach(['pushOrders', 'syncLogistics', 'syncInventory', 'pushAftersales', 'syncAftersales'] as $method)
{
    $result = $service->{$method}();
    if(($result['enabled'] ?? null) !== false)
    {
        throw new RuntimeException('The composed workflow '.$method.' did not retain the disabled integration guard.');
    }
}

echo "WangDian workflow composition tests passed\n";
