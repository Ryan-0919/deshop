<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianSyncCursorWindow;

function assertWangDianCursorWindowSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$cursor = WangDianSyncCursorWindow::completedBefore(1700000001);
assertWangDianCursorWindowSame(
    ['cursor_time'=>1700000000, 'cursor_value'=>PHP_INT_MAX],
    $cursor,
    'A drained cursor must complete only through the previous Unix second.'
);

if(WangDianSyncCursorWindow::isCandidate(1700000001, 20, $cursor, 1700000001))
{
    throw new RuntimeException('A source row from the current second must remain open for the next run.');
}
if(!WangDianSyncCursorWindow::isCandidate(1700000001, 20, $cursor, 1700000002))
{
    throw new RuntimeException('A lower-id update from the closed prior second must be selected on the next run.');
}

echo "WangDian sync cursor window tests passed\n";
