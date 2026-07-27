<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianOrderRevision;

function assertWangDianOrderRevisionSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$order = ['id'=>7, 'order_no'=>'O7', 'upd_time'=>1700000000];
$address = ['id'=>70, 'order_id'=>7, 'address'=>'Road 1', 'add_time'=>1700000000, 'upd_time'=>0];
$details = [['id'=>700, 'order_id'=>7, 'spec_coding'=>'SKU-7', 'buy_number'=>1, 'add_time'=>1700000000, 'upd_time'=>0]];

$first = WangDianOrderRevision::key($order, $address, $details);
assertWangDianOrderRevisionSame(
    $first,
    WangDianOrderRevision::key(array_reverse($order, true), array_reverse($address, true), [array_reverse($details[0], true)]),
    'Associative field order must not change an order revision key'
);

$changedAddress = $address;
$changedAddress['address'] = 'Road 2';
assertWangDianOrderRevisionSame(
    false,
    hash_equals($first, WangDianOrderRevision::key($order, $changedAddress, $details)),
    'Changing an address without changing its second-level timestamp must create a new revision'
);

$addedDetail = $details;
$addedDetail[] = ['id'=>701, 'order_id'=>7, 'spec_coding'=>'SKU-8', 'buy_number'=>1, 'add_time'=>1700000000, 'upd_time'=>0];
assertWangDianOrderRevisionSame(
    false,
    hash_equals($first, WangDianOrderRevision::key($order, $address, $addedDetail)),
    'Adding an order detail with upd_time zero must create a new revision'
);
assertWangDianOrderRevisionSame(
    false,
    hash_equals($first, WangDianOrderRevision::key($order, $address, [])),
    'Deleting order details must create a new revision'
);

echo "WangDianOrderRevision tests passed\n";
