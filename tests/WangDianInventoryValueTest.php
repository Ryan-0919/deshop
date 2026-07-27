<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianInventoryValue;

function assertWangDianInventorySame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

assertWangDianInventorySame(0, WangDianInventoryValue::parse(0), 'Zero inventory must be accepted');
assertWangDianInventorySame(12, WangDianInventoryValue::parse('12'), 'Canonical integer strings must be accepted');
assertWangDianInventorySame(4294967295, WangDianInventoryValue::parse('4294967295'), 'MySQL unsigned INT maximum must be accepted');

foreach(['abc', '5.9', '-1', '01', '', '4294967296', 4294967296, 5.0, true, null] as $invalid)
{
    try
    {
        WangDianInventoryValue::parse($invalid);
        throw new RuntimeException('Malformed inventory must be rejected: '.var_export($invalid, true));
    } catch(InvalidArgumentException $e) {
        assertWangDianInventorySame(
            true,
            str_contains($e->getMessage(), 'inventory'),
            'The validation error must identify the invalid inventory value'
        );
    }
}

echo "WangDianInventoryValue tests passed\n";
