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
    public array $aftersales = [];
    public array $successes = [];
    public array $failures = [];
    public array $logistics = [];
    public array $inventory = [];
    public array $aftersaleStatuses = [];

    public function pendingOrders(int $syncFrom, int $limit, int $now): array
    {
        return array_slice($this->orders, 0, $limit);
    }

    public function pendingAftersales(int $limit, int $now): array
    {
        return array_slice($this->aftersales, 0, $limit);
    }

    public function startAttempt(string $businessType, string $businessKey, int $orderId, array $requestSummary, int $now): bool
    {
        foreach($this->successes as $success)
        {
            if($success[0] === $businessType && $success[1] === $businessKey)
            {
                return false;
            }
        }
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

    public function applyAftersaleStatus(array $record, bool $autoProcess, int $refundment): int
    {
        $this->aftersaleStatuses[] = [$record, $autoProcess, $refundment];
        if(($record['src_no'] ?? '') === 'SHOPXO-AS-99')
        {
            throw new RuntimeException('ShopXO aftersale 99 was not found.');
        }
        return 12;
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
        'aftersale_batch_size' => 10,
        'aftersale_query_lookback' => 604800,
        'aftersale_auto_process' => false,
        'aftersale_refundment' => 0,
        'express_map' => ['YUNDA'=>6],
    ];
}

function wangDianOrderFixture(int $id, string $sku): array
{
    return [
        'key'=>$id.':1700000010',
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
    'new_count'=>1,
    'chg_count'=>0,
    'errors'=>[['tid'=>'O2', 'error'=>'ERP rejected this order.']],
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushOrders();

assertWangDianSyncSame(1, $result['success'], 'A valid order should be marked as synchronized');
assertWangDianSyncSame(2, $result['failed'], 'Local validation and ERP item errors should fail without blocking the batch');
assertWangDianSyncSame('trade_push.php', $client->requests[0]['endpoint'] ?? null, 'Orders must use the trade push endpoint');
$tradeList = json_decode($client->requests[0]['params']['trade_list'] ?? '', true);
assertWangDianSyncSame(['O1', 'O2'], array_column($tradeList, 'tid'), 'Only locally valid orders should be sent');
assertWangDianSyncSame([['order_push', '1:1700000010']], $store->successes, 'Only the ERP-accepted order revision should be marked successful');
$erpOrderFailure = array_values(array_filter($store->failures, static fn(array $failure): bool => $failure[1] === '2:1700000010'))[0] ?? [];
assertWangDianSyncSame(1, $erpOrderFailure[3]['error_count'] ?? null, 'A per-order ERP rejection must retain its response summary');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$store->orders = [wangDianOrderFixture(5, 'SKU-5'), wangDianOrderFixture(6, 'SKU-6')];
$client->responses['trade_push.php'][] = ['code'=>0, 'message'=>'Partial', 'new_count'=>1, 'chg_count'=>0];
$client->responses['trade_push.php'][] = ['code'=>0, 'message'=>'Updated', 'new_count'=>0, 'chg_count'=>1];
$client->responses['trade_push.php'][] = ['code'=>0, 'message'=>'Rejected', 'new_count'=>0, 'chg_count'=>0];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushOrders();

assertWangDianSyncSame(1, $result['success'], 'A count-only partial order response must isolate the accepted order');
assertWangDianSyncSame(1, $result['failed'], 'A count-only partial order response must leave the rejected order retryable');
assertWangDianSyncSame(3, count($client->requests), 'An ambiguous order batch response must fall back to one request per order');
assertWangDianSyncSame([['order_push', '5:1700000010']], $store->successes, 'Only the individually accepted order revision may be marked successful');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$store->orders = [wangDianOrderFixture(8, 'SKU-8')];
$client->responses['trade_push.php'][] = ['code'=>0, 'message'=>'Malformed count', 'new_count'=>'1garbage', 'chg_count'=>0];
$client->responses['trade_push.php'][] = ['code'=>0, 'message'=>'Accepted after retry', 'new_count'=>1, 'chg_count'=>0];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushOrders();

assertWangDianSyncSame(1, $result['success'], 'A malformed batch count must be resolved through an individually validated retry');
assertWangDianSyncSame(2, count($client->requests), 'A malformed order acceptance count must never be trusted as success');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$firstRevision = wangDianOrderFixture(7, 'SKU-7');
$secondRevision = wangDianOrderFixture(7, 'SKU-7');
$secondRevision['key'] = '7:1700002000';
$secondRevision['address']['address'] = 'Road 2';
$store->orders = [$firstRevision];
$client->responses['trade_push.php'][] = ['code'=>0, 'message'=>'Created', 'new_count'=>1, 'chg_count'=>0];
$client->responses['trade_push.php'][] = ['code'=>0, 'message'=>'Updated', 'new_count'=>0, 'chg_count'=>1];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$service->pushOrders();
$store->orders = [$secondRevision];
$result = $service->pushOrders();

assertWangDianSyncSame(1, $result['success'], 'A changed order revision must be pushed as an ERP update');
assertWangDianSyncSame(
    [['order_push', '7:1700000010'], ['order_push', '7:1700002000']],
    $store->successes,
    'Each order revision must have its own idempotency key'
);

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
$client->responses['logistics_sync_query.php'][] = [
    'code'=>0,
    'trades'=>[['rec_id'=>'ack-logistics', 'tid'=>'O1', 'logistics_no'=>'YD1', 'logistics_code_erp'=>'YUNDA']],
];
$client->responses['logistics_sync_ack.php'][] = [
    'code'=>0,
    'message'=>'Ambiguous ACK',
    'errors'=>[['rec_id'=>'another-record', 'error'=>'Not acknowledged']],
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->syncLogistics();
assertWangDianSyncSame(0, $result['success'], 'An ACK error for an unknown logistics record must not confirm the attempted record');
assertWangDianSyncSame(1, $result['failed'], 'An ambiguous logistics ACK must remain retryable');
assertWangDianSyncSame([], $store->successes, 'Ambiguous logistics ACKs must not create successful synchronization logs');

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
$client->responses['api_goods_stock_change_query.php'][] = [
    'code'=>0,
    'stock_change_list'=>[[
        'rec_id'=>'ack-inventory', 'goods_id'=>'34', 'spec_no'=>'SKU-1', 'sync_stock'=>8, 'stock_change_count'=>'10',
    ]],
];
$client->responses['api_goods_stock_change_ack.php'][] = [
    'code'=>0,
    'message'=>'Malformed ACK',
    'errors'=>['missing rec_id'],
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->syncInventory();
assertWangDianSyncSame(0, $result['success'], 'A malformed inventory ACK must not confirm the attempted stock record');
assertWangDianSyncSame(1, $result['failed'], 'A malformed inventory ACK must remain retryable');
assertWangDianSyncSame([], $store->successes, 'Malformed inventory ACKs must not create successful synchronization logs');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$client->responses['api_goods_stock_change_query.php'][] = [
    'code'=>0,
    'stock_change_list'=>[
        ['rec_id'=>'invalid-stock', 'goods_id'=>'34', 'spec_no'=>'SKU-1', 'sync_stock'=>'abc', 'stock_change_count'=>'9'],
    ],
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->syncInventory();

assertWangDianSyncSame(0, $result['success'], 'A malformed ERP inventory value must never be accepted');
assertWangDianSyncSame(1, $result['failed'], 'A malformed ERP inventory value must remain retryable');
assertWangDianSyncSame([], $store->inventory, 'Malformed inventory must be rejected before reaching the persistence store');
assertWangDianSyncSame(1, count($client->requests), 'Malformed inventory must not be acknowledged to ERP');

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

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$store->aftersales = [
    [
        'key'=>'12:2:1700000200',
        'aftersale'=>[
            'id'=>12, 'type'=>1, 'status'=>2, 'price'=>'9.50', 'number'=>1,
            'reason'=>'Return', 'express_name'=>'Yunda', 'express_number'=>'YD12',
            'apply_time'=>1700000000,
        ],
        'order'=>['id'=>1, 'order_no'=>'O1', 'user_id'=>9, 'delivery_time'=>1700000100],
        'detail'=>['id'=>10, 'buy_number'=>1],
    ],
    [
        'key'=>'13:0:1700000201',
        'aftersale'=>[
            'id'=>13, 'type'=>1, 'status'=>0, 'price'=>'9.50', 'number'=>0,
            'reason'=>'Invalid return', 'apply_time'=>1700000000,
        ],
        'order'=>['id'=>2, 'order_no'=>'O2', 'user_id'=>9, 'delivery_time'=>1700000100],
        'detail'=>['id'=>20, 'buy_number'=>1],
    ],
];
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'OK', 'new_count'=>1, 'chg_count'=>0];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushAftersales();

assertWangDianSyncSame(1, $result['success'], 'A valid aftersale should be pushed to ERP');
assertWangDianSyncSame(1, $result['failed'], 'An invalid aftersale must not block the rest of the batch');
assertWangDianSyncSame('sales_refund_push.php', $client->requests[0]['endpoint'] ?? null, 'Aftersales must use the refund push endpoint');
$refundList = json_decode($client->requests[0]['params']['api_refund_list'] ?? '', true);
assertWangDianSyncSame(['SHOPXO-AS-12'], array_column($refundList, 'refund_no'), 'Only locally valid aftersales should be sent');
assertWangDianSyncSame([['aftersale_push', '12:2:1700000200']], $store->successes, 'The accepted aftersale state must be idempotently marked successful');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$store->aftersales = [[
    'key'=>'14:2:1700000202',
    'aftersale'=>[
        'id'=>14, 'type'=>0, 'status'=>2, 'price'=>'1.00', 'number'=>0,
        'reason'=>'Refund', 'apply_time'=>1700000000,
    ],
    'order'=>['id'=>3, 'order_no'=>'O3', 'user_id'=>9, 'delivery_time'=>0],
    'detail'=>['id'=>30, 'buy_number'=>1],
]];
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'OK', 'new_count'=>0, 'chg_count'=>0];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushAftersales();
assertWangDianSyncSame(0, $result['success'], 'A refund omitted by ERP must not be marked successful');
assertWangDianSyncSame(1, $result['failed'], 'A refund-count mismatch must remain retryable');
assertWangDianSyncSame('aftersale_push', $store->failures[0][0] ?? null, 'A count mismatch must be recorded against the refund push');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
foreach([15, 16] as $id)
{
    $store->aftersales[] = [
        'key'=>$id.':2:1700000203',
        'aftersale'=>['id'=>$id, 'type'=>0, 'status'=>2, 'price'=>'1.00', 'number'=>0, 'reason'=>'Refund', 'apply_time'=>1700000000],
        'order'=>['id'=>$id, 'order_no'=>'O'.$id, 'user_id'=>9, 'delivery_time'=>0],
        'detail'=>['id'=>$id * 10, 'buy_number'=>1],
    ];
}
$client->responses['sales_refund_push.php'][] = [
    'code'=>0,
    'message'=>'OK',
    'errors'=>[['refund_no'=>'SHOPXO-AS-16', 'error'=>'ERP rejected this aftersale.']],
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushAftersales();
assertWangDianSyncSame(1, $result['success'], 'An accepted aftersale in a partially rejected batch must succeed');
assertWangDianSyncSame(1, $result['failed'], 'A per-aftersale ERP error must remain retryable');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
foreach([17, 18] as $id)
{
    $store->aftersales[] = [
        'key'=>$id.':2:1700000204',
        'aftersale'=>['id'=>$id, 'type'=>0, 'status'=>2, 'price'=>'1.00', 'number'=>0, 'reason'=>'Refund', 'apply_time'=>1700000000],
        'order'=>['id'=>$id, 'order_no'=>'O'.$id, 'user_id'=>9, 'delivery_time'=>0],
        'detail'=>['id'=>$id * 10, 'buy_number'=>1],
    ];
}
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'Partial', 'new_count'=>1, 'chg_count'=>0];
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'OK', 'new_count'=>0, 'chg_count'=>1];
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'Rejected', 'new_count'=>0, 'chg_count'=>0];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushAftersales();
assertWangDianSyncSame(1, $result['success'], 'A count-only partial response must isolate the accepted aftersale');
assertWangDianSyncSame(1, $result['failed'], 'A count-only partial response must leave only the rejected aftersale retryable');
assertWangDianSyncSame(3, count($client->requests), 'An ambiguous batch response must fall back to one request per aftersale');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$store->aftersales = [[
    'key'=>'20:2:1700000205',
    'aftersale'=>['id'=>20, 'type'=>0, 'status'=>2, 'price'=>'1.00', 'number'=>0, 'reason'=>'Refund', 'apply_time'=>1700000000],
    'order'=>['id'=>20, 'order_no'=>'O20', 'user_id'=>9, 'delivery_time'=>0],
    'detail'=>['id'=>200, 'buy_number'=>1],
]];
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'OK'];
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'OK'];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushAftersales();
assertWangDianSyncSame(1, $result['success'], 'An official count-less response must be verified with an individual idempotent retry');
assertWangDianSyncSame(2, count($client->requests), 'A count-less response without item results must never mark a batch successful directly');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
foreach([21, 22] as $id)
{
    $store->aftersales[] = [
        'key'=>$id.':2:1700000206',
        'aftersale'=>['id'=>$id, 'type'=>0, 'status'=>2, 'price'=>'1.00', 'number'=>0, 'reason'=>'Refund', 'apply_time'=>1700000000],
        'order'=>['id'=>$id, 'order_no'=>'O'.$id, 'user_id'=>9, 'delivery_time'=>0],
        'detail'=>['id'=>$id * 10, 'buy_number'=>1],
    ];
}
$client->responses['sales_refund_push.php'][] = [
    'code'=>0,
    'message'=>'Unknown item error',
    'errors'=>[['refund_no'=>'SHOPXO-AS-999', 'error'=>'Unknown refund']],
];
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'OK', 'new_count'=>1, 'chg_count'=>0];
$client->responses['sales_refund_push.php'][] = ['code'=>0, 'message'=>'OK', 'new_count'=>0, 'chg_count'=>1];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1700001000);
$result = $service->pushAftersales();
assertWangDianSyncSame(2, $result['success'], 'Unknown ERP error identifiers must force individually verified retries');
assertWangDianSyncSame(3, count($client->requests), 'An error for an unattempted refund must never validate the batch response');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$client->responses['refund_query.php'][] = [
    'code'=>0,
    'total_count'=>3,
    'refunds'=>[
        ['refund_id'=>'501', 'src_no'=>'SHOPXO-AS-12', 'process_status'=>90, 'modified'=>'2026-07-25 10:00:00'],
        ['refund_id'=>'502', 'src_no'=>'SHOPXO-AS-99', 'process_status'=>40, 'modified'=>'2026-07-25 10:00:01'],
        ['refund_id'=>'503', 'src_no'=>'', 'api_outer_no'=>'SHOPXO-AS-13', 'process_status'=>30, 'modified'=>'2026-07-25 10:00:02'],
    ],
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();

assertWangDianSyncSame(2, $result['success'], 'Valid ERP aftersale states should be applied locally');
assertWangDianSyncSame(1, $result['failed'], 'One invalid ERP aftersale state must not block the page');
assertWangDianSyncSame('SHOPXO-AS-13', $store->aftersaleStatuses[2][0]['api_outer_no'] ?? null, 'api_outer_no must be used when src_no is empty');
assertWangDianSyncSame(false, $store->aftersaleStatuses[0][1] ?? null, 'Automatic local aftersale actions must default to disabled');
assertWangDianSyncSame(0, $store->aftersaleStatuses[0][2] ?? null, 'The configured refund method must be passed to local processing');
assertWangDianSyncSame('refund_query.php', $client->requests[0]['endpoint'] ?? null, 'ERP aftersale states must use refund_query');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$sameState = [
    'code'=>0,
    'total_count'=>1,
    'refunds'=>[['refund_id'=>'504', 'src_no'=>'SHOPXO-AS-14', 'process_status'=>30, 'modified'=>'2026-07-25 10:01:00']],
];
$client->responses['refund_query.php'][] = $sameState;
$client->responses['refund_query.php'][] = $sameState;
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$service->syncAftersales();
$secondResult = $service->syncAftersales();
assertWangDianSyncSame(0, $secondResult['success'], 'A previously successful ERP state must not be applied again');
assertWangDianSyncSame(1, $secondResult['skipped'], 'A previously successful ERP state must be reported as skipped');
assertWangDianSyncSame(1, count($store->aftersaleStatuses), 'A stable ERP state must be applied only once across task runs');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$pageOne = [];
for($id = 1; $id <= 40; $id++)
{
    $pageOne[] = ['refund_id'=>(string)$id, 'src_no'=>'SHOPXO-AS-'.$id, 'process_status'=>30, 'modified'=>'2026-07-25 11:00:00'];
}
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>41, 'refunds'=>$pageOne];
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>41, 'refunds'=>[
    ['refund_id'=>'41', 'src_no'=>'SHOPXO-AS-41', 'process_status'=>30, 'modified'=>'2026-07-25 11:00:01'],
]];
$config = wangDianTestConfig();
$config['aftersale_query_max_pages'] = 1;
$service = new WangDianSyncService($config, $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(41, $result['success'], 'Refund pagination must drain the complete ERP result set');
assertWangDianSyncSame(2, count($client->requests), 'Refund pagination must continue beyond a configured first-page cap');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$client->responses['refund_query.php'][] = [
    'code'=>0,
    'total_count'=>'40garbage',
    'refunds'=>$pageOne,
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(0, $result['success'], 'A malformed refund total must be rejected before applying the page');
assertWangDianSyncSame(1, $result['failed'], 'A malformed refund total must fail the synchronization run');
assertWangDianSyncSame([], $store->aftersaleStatuses, 'A malformed refund page must not mutate local aftersales');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$duplicatePage = ['code'=>0, 'total_count'=>80, 'refunds'=>$pageOne];
$client->responses['refund_query.php'][] = $duplicatePage;
$client->responses['refund_query.php'][] = $duplicatePage;
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(40, $result['success'], 'A repeated ERP page must not be applied twice');
assertWangDianSyncSame(1, $result['failed'], 'A repeated ERP page must stop the run as an upstream pagination failure');
assertWangDianSyncSame(2, count($client->requests), 'Repeated refund pages must be detected immediately');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$overlappingPage = [];
for($id = 21; $id <= 60; $id++)
{
    $overlappingPage[] = ['refund_id'=>(string)$id, 'src_no'=>'SHOPXO-AS-'.$id, 'process_status'=>30, 'modified'=>'2026-07-25 11:00:00'];
}
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>80, 'refunds'=>$pageOne];
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>80, 'refunds'=>$overlappingPage];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(40, $result['success'], 'Only the first unique refund page may be applied before overlap is detected');
assertWangDianSyncSame(1, $result['failed'], 'Partially overlapping refund pages must fail the synchronization run');
assertWangDianSyncSame(40, count($store->aftersaleStatuses), 'Overlapping refund records must never be applied twice');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>1, 'refunds'=>['malformed-record']];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(0, $result['success'], 'Malformed refund entries must not count toward total_count');
assertWangDianSyncSame(1, $result['failed'], 'Malformed refund entries must fail the pagination run');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$fallbackPageOne = [];
$fallbackPageTwo = [];
for($id = 1; $id <= 40; $id++)
{
    $fallbackPageOne[] = ['src_no'=>'SHOPXO-AS-'.$id, 'process_status'=>30, 'modified'=>'2026-07-25 11:00:00'];
}
for($id = 21; $id <= 60; $id++)
{
    $fallbackPageTwo[] = ['src_no'=>'SHOPXO-AS-'.$id, 'process_status'=>40, 'modified'=>'2026-07-25 11:05:00'];
}
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>80, 'refunds'=>$fallbackPageOne];
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>80, 'refunds'=>$fallbackPageTwo];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(40, $result['success'], 'Fallback source numbers must detect overlap even when other fields change');
assertWangDianSyncSame(1, $result['failed'], 'Overlapping source numbers without refund_id must fail the run');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$client->responses['refund_query.php'][] = ['code'=>0, 'refunds'=>$pageOne];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(0, $result['success'], 'A full refund page without total_count must not be applied');
assertWangDianSyncSame(1, $result['failed'], 'A missing refund total must fail instead of looping indefinitely');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$incompletePage = array_slice($pageOne, 0, 20);
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>80, 'refunds'=>$incompletePage];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(0, $result['success'], 'A short refund page below total_count must not be accepted as complete');
assertWangDianSyncSame(1, $result['failed'], 'An incomplete short refund page must fail the run');
assertWangDianSyncSame([], $store->aftersaleStatuses, 'An incomplete short page must be rejected before local mutations');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$client->responses['refund_query.php'][] = ['code'=>0, 'total_count'=>41, 'refunds'=>$pageOne];
$client->responses['refund_query.php'][] = ['code'=>0, 'refunds'=>[
    ['refund_id'=>'41', 'src_no'=>'SHOPXO-AS-41', 'process_status'=>30, 'modified'=>'2026-07-25 11:00:01'],
]];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$result = $service->syncAftersales();
assertWangDianSyncSame(40, $result['success'], 'Records from validated pages may remain applied when a later page is malformed');
assertWangDianSyncSame(1, $result['failed'], 'Every refund page must repeat a valid total_count');

$client = new FakeWangDianClient();
$store = new FakeWangDianStore();
$store->orders = [wangDianOrderFixture(19, 'SKU-19')];
$client->responses['trade_push.php'][] = [
    'code'=>0,
    'message'=>'Rejected receiver_mobile=13800138000, address: Beijing Road 1',
    'new_count'=>0,
    'chg_count'=>0,
    'errors'=>[['tid'=>'O19', 'error'=>'联系电话：13912345678；收货地址：Shanghai Road 2']],
];
$service = new WangDianSyncService(wangDianTestConfig(), $client, $store, static fn(): int => 1784948400);
$service->pushOrders();
$loggedFailure = $store->failures[0] ?? [];
assertWangDianSyncSame(false, str_contains($loggedFailure[2] ?? '', '13912345678'), 'ERP item errors must mask phone numbers before logging');
assertWangDianSyncSame(false, str_contains($loggedFailure[2] ?? '', 'Shanghai Road 2'), 'ERP item errors must mask addresses before logging');
assertWangDianSyncSame(false, str_contains($loggedFailure[3]['message'] ?? '', '13800138000'), 'ERP response summaries must mask phone numbers');
assertWangDianSyncSame(false, str_contains($loggedFailure[3]['message'] ?? '', 'Beijing Road 1'), 'ERP response summaries must mask addresses');

echo "WangDianSyncService tests passed\n";
