<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\RefundRequestNoService;

function assertRefundRequestNoSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$first = RefundRequestNoService::ForAftersale(['id'=>12]);
$retry = RefundRequestNoService::ForAftersale(['id'=>12]);

assertRefundRequestNoSame('SHOPXO-AS-12', $first, 'An aftersale must have a stable payment refund request number');
assertRefundRequestNoSame($first, $retry, 'Retrying the same aftersale must reuse its payment refund request number');
assertRefundRequestNoSame(
    'SHOPXO-AS-12',
    RefundRequestNoService::Provided(['refund_no'=>' SHOPXO-AS-12 ']),
    'Payment plugins must prefer the provided stable refund request number'
);
assertRefundRequestNoSame('', RefundRequestNoService::Provided([]), 'Legacy callers without a request number must retain plugin fallback behavior');

try
{
    RefundRequestNoService::ForAftersale(['id'=>0]);
    throw new RuntimeException('An invalid aftersale id must be rejected');
} catch(InvalidArgumentException $e) {
    assertRefundRequestNoSame(true, str_contains($e->getMessage(), 'id'), 'The validation error must identify the invalid aftersale id');
}

echo "RefundRequestNoService tests passed\n";
