<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianClientInterface;
use app\integration\wangdian\WangDianApiException;
use app\integration\wangdian\WangDianStoreInterface;
use app\integration\wangdian\WangDianSyncService;

function assertWangDianSyncSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

final class FakeWangDianClient implements WangDianClientInterface
{
    public array $requests = [];
    public array $responses = [];

    public function request(string $endpoint, array $businessParams = []): array
    {
        $this->requests[] = ['endpoint'=>$endpoint, 'params'=>$businessParams];
        $response = array_shift($this->responses[$endpoint]);
        if($response instanceof Throwable)
        {
            throw $response;
        }
        return $response;
    }
}

final class FakeWangDianStore implements WangDianStoreInterface
{
    public array $orders = [];
    public array $successes = [];
    public array $failures = [];
    public array $logistics = [];
    public array $inventory = [];

    public function pendingOrders(int $syncFrom, int $limit, int $now): array
    {
        return array_slice($this->orders, 0, $limit);
    }

    public function startAttempt(string $businessType, string $businessKey, int $orderId, array $requestSummary, int $now): bool
    {
        return true;
    }

    public function markSuccess(string $businessType, string $businessKey, array $responseSummary, int $now): void
    {
        $this->successes[] = [$businessType, $businessKey];
    }

    public function markFailure(
        string $businessType,
        string $businessKey,
        string $error,
        int $now,
        array $responseSummary = []
    ): void
    {
        $this->failures[] = [$businessType, $businessKey, $error, $responseSummary];
    }

    public function applyLogistics(array $record, array $expressMap): int
    {
        $this->logistics[] = $record;
        if(($record['rec_id'] ?? '') === 'bad')
        {
            throw new RuntimeException('Unknown express mapping.');
        }
        return 12;
    }

    public function applyInventory(array $record, int $warehouseId): int
    {
        $this->inventory[] = $record;
        if(($record['rec_id'] ?? '') === 'bad')
        {
            throw new RuntimeException('SKU was not found.');
        }
        return 34;
    }
}

function wangDianTestConfig(): array
{
    return [
        'enabled' => true,
        'base_url' => 'https://sandbox.wangdian.cn/openapi2',
        'sid' => 'sid',
        'appkey' => 'appkey',
        'appsecret' => 'secret',
        'shop_no' => 'shop',
        'order_sync_from' => 1700000000,
        'shopxo_warehouse_id' => 0,
        'order_batch_size' => 50,
        'logistics_batch_size' => 100,
        'inventory_batch_size' => 100,
        'express_map' => ['YUNDA'=>6],
    ];
}

function wangDianOrderFixture(int $id, string $sku): array
{
    return [
        'order' => [
            'id'=>$id, 'order_no'=>'O'.$id, 'user_id'=>9, 'status'=>2, 'pay_status'=>1,
            'add_time'=>1700000010, 'pay_time'=>1700000020, 'total_price'=>'10.00',
            'pay_price'=>'10.00', 'preferential_price'=>'0.00', 'increase_price'=>'0.00',
        ],
        'address' => [
            'name'=>'Test', 'tel'=>'13800000000', 'province_name'=>'Beijing',
            'city_name'=>'Beijing', 'county_name'=>'Haidian', 'address'=>'Road 1',
        ],
        'details' => [[
            'id'=>$id * 10, 'goods_id'=>34, 'title'=>'Product', 'coding'=>'SPU34',
            'spec_coding'=>$sku, 'buy_number'=>1, 'price'=>'10.00', 'total_price'=>'10.00',
        ]],
    ];
}

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$invalidOrder = wangDianOrderFixture(3, 'DUPLICATE');
$invalidOrder['validation_error'] = 'SKU DUPLICATE must be unique in ShopXO.';
$store->orders = [wangDianOrderFixture(1, 'SKU-1'), wangDianOrderFixture(2, 'SKU-2'), $invalidOrder];
$client->responses['trade_push.php'][] = [
    'code'=>0,
    'message'=>'OK',
    'errors'=>[['tid'=>'O2', 'error'=>'ERP rejected this order.']],
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushOrders();

assertWangDianSyncSame(1, $result['success'], 'A valid order should be marked as synchronized');
assertWangDianSyncSame(2, $result['failed'], 'Local validation and ERP item errors should fail without blocking the batch');
assertWangDianSyncSame('trade_push.php', $client->requests[0]['endpoint'] ?? null, 'Orders must use the trade push endpoint');
$tradeList = json_decode($client->requests[0]['params']['trade_list'] ?? '', true);
assertWangDianSyncSame(['O1', 'O2'], array_column($tradeList, 'tid'), 'Only locally valid orders should be sent');
assertWangDianSyncSame([['order_push', '1']], $store->successes, 'Only the ERP-accepted order should be marked successful');
$erpOrderFailure = array_values(array_filter($store->failures, static fn(array $failure): bool => $failure[1] === '2'))[0] ?? [];
assertWangDianSyncSame(1, $erpOrderFailure[3]['error_count'] ?? null, 'A per-order ERP rejection must retain its response summary');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$client->responses['logistics_sync_query.php'][] = [
    'code'=>0,
    'trades'=>[
        ['rec_id'=>'ok', 'tid'=>'O1', 'logistics_no'=>'YD1', 'logistics_code_erp'=>'YUNDA'],
        ['rec_id'=>'bad', 'tid'=>'O2', 'logistics_no'=>'YD2', 'logistics_code_erp'=>'UNKNOWN'],
    ],
];
$client->responses['logistics_sync_ack.php'][] = ['code'=>0, 'message'=>'OK'];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->syncLogistics();

assertWangDianSyncSame(1, $result['success'], 'A shipment applied locally should be acknowledged as successful');
assertWangDianSyncSame(1, $result['failed'], 'A shipment rejected locally should be acknowledged as failed');
$logisticsAck = json_decode($client->requests[1]['params']['logistics_list'] ?? '', true);
assertWangDianSyncSame([0, 1], array_column($logisticsAck, 'status'), 'Shipment acknowledgements must preserve each local outcome');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$client->responses['api_goods_stock_change_query.php'][] = [
    'code'=>0,
    'stock_change_list'=>[
        ['rec_id'=>'ok', 'goods_id'=>'34', 'spec_no'=>'SKU-1', 'sync_stock'=>8, 'stock_change_count'=>'7'],
        ['rec_id'=>'bad', 'goods_id'=>'35', 'spec_no'=>'MISSING', 'sync_stock'=>5, 'stock_change_count'=>'8'],
    ],
];
$client->responses['api_goods_stock_change_ack.php'][] = ['code'=>0, 'message'=>'OK'];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->syncInventory();

assertWangDianSyncSame(1, $result['success'], 'A stock record applied locally should be acknowledged');
assertWangDianSyncSame(1, $result['failed'], 'A stock record rejected locally should remain failed');
$stockAck = json_decode($client->requests[1]['params']['stock_sync_list'] ?? '', true);
assertWangDianSyncSame(['ok'], array_column($stockAck, 'rec_id'), 'Only locally committed stock records may be acknowledged');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$store->orders = [wangDianOrderFixture(4, 'SKU-4')];
$client->responses['trade_push.php'][] = new WangDianApiException('Shop is not authorized.', [
    'code'=>1001,
    'message'=>'Shop is not authorized.',
]);
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushOrders();

assertWangDianSyncSame(1, $result['failed'], 'A top-level ERP rejection must fail the attempted order');
assertWangDianSyncSame(1001, $store->failures[0][3]['code'] ?? null, 'A top-level ERP rejection must retain its response summary');

echo "WangDianSyncService tests passed\n";
