<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianBatchResponse;

function assertWangDianBatchSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$attempted = ['ORDER-1', 'ORDER-2'];
$errors = WangDianBatchResponse::errors([
    'errors'=>[['tid'=>'ORDER-2', 'message'=>'invalid sku']],
], $attempted, ['tid', 'order_no'], 'Order synchronization failed.');
assertWangDianBatchSame(['ORDER-2'=>'invalid sku'], $errors, 'Known batch errors must remain attached to their record.');

$unknown = WangDianBatchResponse::errors([
    'errors'=>[['tid'=>'ORDER-3', 'message'=>'invalid order']],
], $attempted, ['tid', 'order_no'], 'Order synchronization failed.');
assertWangDianBatchSame(
    ['*'=>'Wangdiantong batch response referenced an unexpected record.'],
    $unknown,
    'Unknown batch error identifiers must fail the attempted batch.'
);

$malformed = WangDianBatchResponse::errors([
    'errors'=>['not-an-error-record'],
], $attempted, ['tid', 'order_no'], 'Order synchronization failed.');
assertWangDianBatchSame(
    ['*'=>'Wangdiantong batch response contained a malformed error record.'],
    $malformed,
    'Malformed batch error entries must fail the attempted batch.'
);

if(!WangDianBatchResponse::countMatches(['new_count'=>'1', 'chg_count'=>0], $attempted, $errors))
{
    throw new RuntimeException('Accepted count must match successful attempts after record-level errors.');
}
if(WangDianBatchResponse::countMatches(['new_count'=>2], $attempted, $errors))
{
    throw new RuntimeException('Contradictory accepted counts must trigger individual retry.');
}
if(!WangDianBatchResponse::countMatches([], ['REFUND-1'], [], true))
{
    throw new RuntimeException('The documented single-refund implicit success response must remain supported.');
}

echo "WangDian batch response tests passed\n";
