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

WangDianAftersaleMapper::assertRemoteLink(
    ['tid'=>'O100', 'type'=>2, 'refund_amount'=>'9.5000', 'refund_order_list'=>[['oid'=>'O100-101']]],
    ['id'=>12, 'type'=>1, 'price'=>'9.50', 'order_no'=>'O100', 'order_detail_id'=>101],
    ['delivery_time'=>1700000100]
);

try
{
    WangDianAftersaleMapper::assertRemoteLink(
        ['tid'=>'O100', 'type'=>2, 'refund_amount'=>'9.50', 'refund_order_list'=>[['oid'=>'O100-999']]],
        ['id'=>12, 'type'=>1, 'price'=>'9.50', 'order_no'=>'O100', 'order_detail_id'=>101],
        ['delivery_time'=>1700000100]
    );
    throw new RuntimeException('An ERP refund linked to another order detail must be rejected');
} catch(RuntimeException $e) {
    assertWangDianAftersaleSame(true, str_contains($e->getMessage(), 'detail'), 'The link validation error must identify the order-detail mismatch');
}

try
{
    WangDianAftersaleMapper::assertRemoteLink(
        ['tid'=>'O100', 'type'=>2, 'refund_amount'=>'8.50', 'refund_order_list'=>[['oid'=>'O100-101']]],
        ['id'=>12, 'type'=>1, 'price'=>'9.50', 'order_no'=>'O100', 'order_detail_id'=>101],
        ['delivery_time'=>1700000100]
    );
    throw new RuntimeException('An ERP refund with another amount must be rejected');
} catch(RuntimeException $e) {
    assertWangDianAftersaleSame(true, str_contains($e->getMessage(), 'amount'), 'The link validation error must identify the amount mismatch');
}

try
{
    WangDianAftersaleMapper::map(
        ['id'=>14, 'type'=>1, 'status'=>0, 'price'=>'1', 'number'=>0, 'apply_time'=>1700000000],
        ['order_no'=>'O102', 'user_id'=>10, 'delivery_time'=>1700000100],
        ['id'=>103, 'buy_number'=>1],
        'shop'
    );
    throw new RuntimeException('A return with zero quantity must be rejected');
} catch(InvalidArgumentException $e) {
    assertWangDianAftersaleSame(true, str_contains($e->getMessage(), 'quantity'), 'The validation error must explain the invalid return quantity');
}

echo "WangDianAftersaleMapper tests passed\n";
