<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianAftersaleStatePolicy;

function assertWangDianAftersaleActionSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

assertWangDianAftersaleActionSame('cancel', WangDianAftersaleStatePolicy::action(10), 'ERP cancellation must cancel the ShopXO aftersale');
assertWangDianAftersaleActionSame('refuse', WangDianAftersaleStatePolicy::action(40), 'ERP refusal must refuse the ShopXO aftersale');
assertWangDianAftersaleActionSame('complete', WangDianAftersaleStatePolicy::action(90), 'ERP completion must complete the ShopXO aftersale');
assertWangDianAftersaleActionSame('approve', WangDianAftersaleStatePolicy::action(30), 'ERP approval progress must approve a return request');
assertWangDianAftersaleActionSame('wait', WangDianAftersaleStatePolicy::action(15), 'An ERP waiting status must not change the ShopXO aftersale');

$unknownRejected = false;
try
{
    WangDianAftersaleStatePolicy::action(999);
} catch(UnexpectedValueException $e) {
    $unknownRejected = str_contains($e->getMessage(), '999');
}
assertWangDianAftersaleActionSame(true, $unknownRejected, 'An unknown ERP process status must be rejected for retry');

$malformedRejected = false;
try
{
    WangDianAftersaleStatePolicy::action('90oops');
} catch(UnexpectedValueException $e) {
    $malformedRejected = str_contains($e->getMessage(), '90oops');
}
assertWangDianAftersaleActionSame(true, $malformedRejected, 'A malformed ERP process status must not be coerced into a valid action');

echo "WangDianAftersaleStatePolicy tests passed\n";
