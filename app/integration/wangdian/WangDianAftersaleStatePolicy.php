<?php
namespace app\integration\wangdian;

final class WangDianAftersaleStatePolicy
{
    private const ACTIONS = [
        10 => 'cancel',
        15 => 'wait',
        20 => 'wait',
        30 => 'approve',
        40 => 'refuse',
        50 => 'approve',
        52 => 'approve',
        54 => 'approve',
        55 => 'approve',
        56 => 'approve',
        58 => 'approve',
        59 => 'approve',
        60 => 'approve',
        63 => 'approve',
        64 => 'approve',
        65 => 'approve',
        69 => 'approve',
        70 => 'approve',
        71 => 'approve',
        80 => 'approve',
        90 => 'complete',
    ];

    public static function action(int $processStatus): string
    {
        if(!array_key_exists($processStatus, self::ACTIONS))
        {
            throw new \UnexpectedValueException('Unsupported Wangdiantong aftersale process status '.$processStatus.'.');
        }
        return self::ACTIONS[$processStatus];
    }
}
