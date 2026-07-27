<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianAftersaleMapper;

function assertWangDianAftersaleSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$return = WangDianAftersaleMapper::map(
    [
        'id'=>12, 'type'=>1, 'status'=>2, 'price'=>'9.50', 'number'=>2,
        'reason'=>'尺码不合适', 'msg'=>'申请退货', 'express_name'=>'韵达快递',
        'express_number'=>'YD123', 'apply_time'=>1700000000,
    ],
    ['order_no'=>'O100', 'user_id'=>9, 'delivery_time'=>1700000100],
    ['id'=>101, 'buy_number'=>3],
    'shop'
);

assertWangDianAftersaleSame(127, $return['platform_id'], 'Self-operated ShopXO aftersales must use platform 127');
assertWangDianAftersaleSame('SHOPXO-AS-12', $return['refund_no'], 'The ERP refund number must be stable and unique');
assertWangDianAftersaleSame(3, $return['type'], 'A ShopXO return/refund must be an ERP return');
assertWangDianAftersaleSame('goods_receiving', $return['status'], 'A shipped return must wait for ERP receipt');
assertWangDianAftersaleSame('9.50', $return['refund_fee'], 'Refund money must retain two decimal places');
assertWangDianAftersaleSame('YD123', $return['logistics_no'], 'Return tracking must be pushed after the buyer ships');
assertWangDianAftersaleSame(
    [['oid'=>'O100-101', 'num'=>2]],
    $return['order_list'],
    'The refund line must use the same oid as trade_push'
);

$refundOnly = WangDianAftersaleMapper::map(
    [
        'id'=>13, 'type'=>0, 'status'=>4, 'price'=>'1', 'number'=>0,
        'reason'=>'不需要了', 'apply_time'=>1700000000,
    ],
    ['order_no'=>'O101', 'user_id'=>10, 'delivery_time'=>0],
    ['id'=>102, 'buy_number'=>4],
    'shop'
);
assertWangDianAftersaleSame(1, $refundOnly['type'], 'An unshipped refund must use the ERP pre-shipment refund type');
assertWangDianAftersaleSame('seller_refuse', $refundOnly['status'], 'A refused ShopXO aftersale must close as seller-refused in ERP');
assertWangDianAftersaleSame(4, $refundOnly['order_list'][0]['num'], 'A refund-only line must still send a positive item quantity');

WangDianAftersaleMapper::validateRemoteLinkAndGetAction(
    [
        'tid'=>'O100', 'type'=>2, 'process_status'=>90,
        'refund_amount'=>'9.5000', 'actual_refund_amount'=>'9.50',
        'refund_order_list'=>[['oid'=>'O100-101', 'refund_num'=>2]],
    ],
    ['id'=>12, 'type'=>1, 'price'=>'9.50', 'number'=>2, 'order_no'=>'O100', 'order_detail_id'=>101],
    ['delivery_time'=>1700000100],
    ['buy_number'=>3]
);

$actualAmountRejected = false;
$detailMismatchRejected = false;
try
{
    WangDianAftersaleMapper::validateRemoteLinkAndGetAction(
        [
            'tid'=>'O100', 'type'=>2, 'process_status'=>90,
            'refund_amount'=>'9.50', 'actual_refund_amount'=>'8.50',
            'refund_order_list'=>[['oid'=>'O100-101', 'refund_num'=>2]],
        ],
        ['id'=>12, 'type'=>1, 'price'=>'9.50', 'number'=>2, 'order_no'=>'O100', 'order_detail_id'=>101],
        ['delivery_time'=>1700000100],
        ['buy_number'=>3]
    );
} catch(RuntimeException $e) {
    $actualAmountRejected = str_contains($e->getMessage(), 'actual');
}
assertWangDianAftersaleSame(true, $actualAmountRejected, 'A completed ERP refund with another actual amount must be rejected');

$malformedAmountRejected = false;
try
{
    WangDianAftersaleMapper::validateRemoteLinkAndGetAction(
        [
            'tid'=>'O100', 'type'=>2, 'process_status'=>90,
            'refund_amount'=>'9.50junk', 'actual_refund_amount'=>'9.50',
            'refund_order_list'=>[['oid'=>'O100-101', 'refund_num'=>2]],
        ],
        ['id'=>12, 'type'=>1, 'price'=>'9.50', 'number'=>2, 'order_no'=>'O100', 'order_detail_id'=>101],
        ['delivery_time'=>1700000100],
        ['buy_number'=>3]
    );
} catch(RuntimeException $e) {
    $malformedAmountRejected = str_contains($e->getMessage(), 'amount');
}
assertWangDianAftersaleSame(true, $malformedAmountRejected, 'A malformed ERP refund amount must not be coerced into a valid amount');

$refundQuantityRejected = false;
try
{
    WangDianAftersaleMapper::validateRemoteLinkAndGetAction(
        [
            'tid'=>'O100', 'type'=>2, 'process_status'=>90,
            'refund_amount'=>'9.50', 'actual_refund_amount'=>'9.50',
            'refund_order_list'=>[['oid'=>'O100-101', 'refund_num'=>1]],
        ],
        ['id'=>12, 'type'=>1, 'price'=>'9.50', 'number'=>2, 'order_no'=>'O100', 'order_detail_id'=>101],
        ['delivery_time'=>1700000100],
        ['buy_number'=>3]
    );
} catch(RuntimeException $e) {
    $refundQuantityRejected = str_contains($e->getMessage(), 'quantity');
}
assertWangDianAftersaleSame(true, $refundQuantityRejected, 'A completed ERP refund with another item quantity must be rejected');

$fractionalQuantityRejected = false;
try
{
    WangDianAftersaleMapper::validateRemoteLinkAndGetAction(
        [
            'tid'=>'O100', 'type'=>2, 'process_status'=>90,
            'refund_amount'=>'9.50', 'actual_refund_amount'=>'9.50',
            'refund_order_list'=>[['oid'=>'O100-101', 'refund_num'=>'2.5']],
        ],
        ['id'=>12, 'type'=>1, 'price'=>'9.50', 'number'=>2, 'order_no'=>'O100', 'order_detail_id'=>101],
        ['delivery_time'=>1700000100],
        ['buy_number'=>3]
    );
} catch(RuntimeException $e) {
    $fractionalQuantityRejected = str_contains($e->getMessage(), 'quantity');
}
assertWangDianAftersaleSame(true, $fractionalQuantityRejected, 'A fractional ERP refund quantity must be rejected');

WangDianAftersaleMapper::validateRemoteLinkAndGetAction(
    [
        'tid'=>'O101', 'type'=>1, 'process_status'=>90,
        'refund_amount'=>'1.00', 'actual_refund_amount'=>'1.00',
        'refund_order_list'=>[['oid'=>'O101-102', 'refund_num'=>4]],
    ],
    ['id'=>13, 'type'=>0, 'price'=>'1.00', 'number'=>0, 'order_no'=>'O101', 'order_detail_id'=>102],
    ['delivery_time'=>0],
    ['buy_number'=>4]
);

try
{
    WangDianAftersaleMapper::validateRemoteLinkAndGetAction(
        ['tid'=>'O100', 'type'=>2, 'process_status'=>20, 'refund_amount'=>'9.50', 'refund_order_list'=>[['oid'=>'O100-999']]],
        ['id'=>12, 'type'=>1, 'price'=>'9.50', 'order_no'=>'O100', 'order_detail_id'=>101],
        ['delivery_time'=>1700000100],
        ['buy_number'=>3]
    );
} catch(RuntimeException $e) {
    $detailMismatchRejected = str_contains($e->getMessage(), 'detail');
}
assertWangDianAftersaleSame(true, $detailMismatchRejected, 'An ERP refund linked to another order detail must be rejected');

$amountMismatchRejected = false;
try
{
    WangDianAftersaleMapper::validateRemoteLinkAndGetAction(
        ['tid'=>'O100', 'type'=>2, 'process_status'=>20, 'refund_amount'=>'8.50', 'refund_order_list'=>[['oid'=>'O100-101']]],
        ['id'=>12, 'type'=>1, 'price'=>'9.50', 'order_no'=>'O100', 'order_detail_id'=>101],
        ['delivery_time'=>1700000100],
        ['buy_number'=>3]
    );
} catch(RuntimeException $e) {
    $amountMismatchRejected = str_contains($e->getMessage(), 'amount');
}
assertWangDianAftersaleSame(true, $amountMismatchRejected, 'An ERP refund with another amount must be rejected');

$invalidQuantityRejected = false;
try
{
    WangDianAftersaleMapper::map(
        ['id'=>14, 'type'=>1, 'status'=>0, 'price'=>'1', 'number'=>0, 'apply_time'=>1700000000],
        ['order_no'=>'O102', 'user_id'=>10, 'delivery_time'=>1700000100],
        ['id'=>103, 'buy_number'=>1],
        'shop'
    );
} catch(InvalidArgumentException $e) {
    $invalidQuantityRejected = str_contains($e->getMessage(), 'quantity');
}
assertWangDianAftersaleSame(true, $invalidQuantityRejected, 'A return with zero quantity must be rejected');

echo "WangDianAftersaleMapper tests passed\n";
