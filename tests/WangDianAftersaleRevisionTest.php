<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianAftersaleRevision;

function assertWangDianAftersaleRevisionSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$aftersale = [
    'id'=>12,
    'type'=>1,
    'status'=>2,
    'price'=>'9.50',
    'number'=>1,
    'reason'=>'Return',
    'msg'=>'Package damaged',
    'express_name'=>'Yunda',
    'express_number'=>'YD12',
    'apply_time'=>1700000000,
];
$order = ['id'=>1, 'order_no'=>'O1', 'user_id'=>9, 'delivery_time'=>1700000100];
$detail = ['id'=>10, 'buy_number'=>1];

$first = WangDianAftersaleRevision::key($aftersale, $order, $detail);
$changedAmount = $aftersale;
$changedAmount['price'] = '8.50';
assertWangDianAftersaleRevisionSame(
    false,
    hash_equals($first, WangDianAftersaleRevision::key($changedAmount, $order, $detail)),
    'Changing an aftersale amount without changing status must create a new revision'
);
$changedLogistics = $aftersale;
$changedLogistics['express_number'] = 'YD13';
assertWangDianAftersaleRevisionSame(
    false,
    hash_equals($first, WangDianAftersaleRevision::key($changedLogistics, $order, $detail)),
    'Changing return logistics without changing status must create a new revision'
);
assertWangDianAftersaleRevisionSame(
    $first,
    WangDianAftersaleRevision::key(array_reverse($aftersale, true), array_reverse($order, true), array_reverse($detail, true)),
    'Associative field order must not change an aftersale revision'
);

echo "WangDianAftersaleRevision tests passed\n";
