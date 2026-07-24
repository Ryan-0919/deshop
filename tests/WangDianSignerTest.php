<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianSigner;

function assertWangDianSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$params = [
    'appkey' => 'test2-xx',
    'page_no' => '0',
    'end_time' => '2016-08-01 13:00:00',
    'start_time' => '2016-08-01 12:00:00',
    'page_size' => '40',
    'sid' => 'test2',
    'timestamp' => '1470042310',
];

assertWangDianSame(
    'ad4e6fe037ea6e3ba4768317be9d1309',
    WangDianSigner::sign($params, '12345'),
    'The signer must match the worked example in the Wangdiantong documentation'
);

assertWangDianSame(
    '6940afb41cd63368794c20a2c38d362a',
    WangDianSigner::sign(['name' => '韵达', 'timestamp' => '1470042310'], 'secret'),
    'The signer must count UTF-8 bytes consistently'
);

echo "WangDianSigner tests passed\n";
