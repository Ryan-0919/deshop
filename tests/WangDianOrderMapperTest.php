<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianOrderMapper;

function assertWangDianOrderSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$trade = WangDianOrderMapper::map([
    'id' => 12,
    'order_no' => 'D202607240001',
    'user_id' => 8,
    'add_time' => 1767225600,
    'pay_time' => 1767225660,
    'price' => '20.00',
    'total_price' => '19.00',
    'pay_price' => '19.00',
    'preferential_price' => '3.00',
    'increase_price' => '2.00',
    'user_note' => '尽快发货🚚',
], [
    'name' => '张三',
    'tel' => '13800138000',
    'province_name' => '北京市',
    'city_name' => '北京市',
    'county_name' => '海淀区',
    'address' => '花园路 1 号',
], [
    [
        'id' => 101,
        'goods_id' => 51,
        'title' => '测试商品 A',
        'price' => '5.00',
        'total_price' => '10.00',
        'buy_number' => 2,
        'coding' => 'SPU-51',
        'spec_coding' => 'SKU-A',
        'spec' => '[{"type":"颜色","value":"黑色"}]',
    ],
    [
        'id' => 102,
        'goods_id' => 52,
        'title' => '测试商品 B',
        'price' => '10.00',
        'total_price' => '10.00',
        'buy_number' => 1,
        'coding' => 'SPU-52',
        'spec_coding' => 'SKU-B',
        'spec' => '[{"type":"尺码","value":"L"}]',
    ],
]);

assertWangDianOrderSame('D202607240001', $trade['tid'] ?? null, 'The ShopXO order number must be the external order id');
assertWangDianOrderSame(30, $trade['trade_status'] ?? null, 'A paid order must be sent as waiting for shipment');
assertWangDianOrderSame(2, $trade['pay_status'] ?? null, 'A paid ShopXO order must use the paid Wangdiantong status');
assertWangDianOrderSame('19.00', $trade['paid'] ?? null, 'The paid amount must match the ShopXO payment amount');
assertWangDianOrderSame('2.00', $trade['post_amount'] ?? null, 'The ShopXO increase amount is the phase-one shipping amount');
assertWangDianOrderSame('尽快发货', $trade['buyer_message'] ?? null, 'Unsupported four-byte characters must not be sent to Wangdiantong');
assertWangDianOrderSame('SKU-A', $trade['order_list'][0]['spec_no'] ?? null, 'The ShopXO specification code must be the ERP merchant SKU');
assertWangDianOrderSame('黑色', $trade['order_list'][0]['spec_name'] ?? null, 'The human-readable specification value must be included');
assertWangDianOrderSame('1.50', $trade['order_list'][0]['share_discount'] ?? null, 'Order discounts must be allocated to line items');
assertWangDianOrderSame('1.50', $trade['order_list'][1]['share_discount'] ?? null, 'The final line must absorb any discount rounding difference');

$zeroPaidTrade = WangDianOrderMapper::map(
    [
        'order_no'=>'FREE-1', 'user_id'=>1, 'add_time'=>1767225600, 'pay_time'=>1767225660,
        'pay_price'=>'0.00', 'total_price'=>'10.00',
    ],
    [
        'name'=>'Test', 'tel'=>'13800138000', 'province_name'=>'Beijing',
        'city_name'=>'Beijing', 'county_name'=>'Haidian', 'address'=>'Road 1',
    ],
    [[
        'id'=>201, 'goods_id'=>1, 'title'=>'Gift', 'price'=>'10.00', 'total_price'=>'10.00',
        'buy_number'=>1, 'coding'=>'SPU-1', 'spec_coding'=>'SKU-FREE',
    ]]
);
assertWangDianOrderSame('0.00', $zeroPaidTrade['paid'] ?? null, 'A fully discounted order must keep its actual zero payment amount');

$repeatedSkuTrade = WangDianOrderMapper::map(
    ['order_no'=>'REPEAT-1', 'user_id'=>1, 'pay_price'=>'2.00'],
    [
        'name'=>'Test', 'tel'=>'13800138000', 'province_name'=>'Beijing',
        'city_name'=>'Beijing', 'county_name'=>'Haidian', 'address'=>'Road 1',
    ],
    [
        ['id'=>301, 'goods_id'=>1, 'title'=>'Item', 'price'=>'1.00', 'total_price'=>'1.00', 'buy_number'=>1, 'spec_coding'=>'SKU-SAME'],
        ['id'=>302, 'goods_id'=>1, 'title'=>'Item', 'price'=>'1.00', 'total_price'=>'1.00', 'buy_number'=>1, 'spec_coding'=>'SKU-SAME'],
    ]
);
assertWangDianOrderSame(
    ['REPEAT-1-301', 'REPEAT-1-302'],
    array_column($repeatedSkuTrade['order_list'], 'oid'),
    'Repeated SKU lines must remain valid and use distinct detail identifiers'
);

try
{
    WangDianOrderMapper::map(
        array_merge($trade, ['order_no' => 'bad']),
        ['name' => '张三', 'tel' => '13800138000', 'province_name' => '北京', 'city_name' => '北京', 'county_name' => '海淀区', 'address' => '花园路'],
        [['id' => 1, 'goods_id' => 1, 'title' => '商品', 'price' => 1, 'total_price' => 1, 'buy_number' => 1, 'coding' => 'SPU', 'spec_coding' => '']]
    );
    throw new RuntimeException('An order without a SKU code must be rejected');
} catch(InvalidArgumentException $e) {
    assertWangDianOrderSame(true, str_contains($e->getMessage(), 'SKU'), 'The validation error must identify the missing SKU');
    assertWangDianOrderSame(true, str_contains($e->getMessage(), 'goods 1'), 'The validation error must identify the affected goods');
}

echo "WangDianOrderMapper tests passed\n";
