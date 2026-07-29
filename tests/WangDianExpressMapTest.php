<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianExpressMap;

function assertWangDianExpressMapSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$map = [
    'YDKD001'=>6,
    'ZGYZ001'=>7,
    'YZXB001'=>7,
    'EMS001'=>15,
    'EMSJJ001'=>15,
    'SF001'=>1,
    'XCX-SF'=>1,
    'YT001'=>16,
    'ZTKD001'=>4,
    'JDWL001'=>17,
    'JDCP001'=>17,
];

foreach($map as $code=>$expressId)
{
    assertWangDianExpressMapSame(
        $expressId,
        WangDianExpressMap::resolve($map, $code),
        'Configured ERP express code '.$code.' must resolve to its ShopXO express ID'
    );
}

assertWangDianExpressMapSame(
    6,
    WangDianExpressMap::resolve([' YDKD001 '=>6], '  ydkd001  '),
    'ERP express mappings must be case-insensitive and trim configured and returned codes'
);

$unknownRejected = false;
try {
    WangDianExpressMap::resolve($map, 'UNKNOWN');
} catch(RuntimeException $e) {
    $unknownRejected = str_contains($e->getMessage(), 'UNKNOWN');
}
assertWangDianExpressMapSame(true, $unknownRejected, 'An unmapped ERP express code must be rejected with its code');

echo "WangDianExpressMap tests passed\n";
