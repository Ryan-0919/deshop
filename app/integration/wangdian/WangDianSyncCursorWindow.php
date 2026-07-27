<?php
namespace app\integration\wangdian;

final class WangDianSyncCursorWindow
{
    public static function completedBefore(int $now): array
    {
        if($now <= 0)
        {
            return ['cursor_time'=>0, 'cursor_value'=>0];
        }
        return ['cursor_time'=>$now-1, 'cursor_value'=>PHP_INT_MAX];
    }

    public static function isCandidate(int $sourceTime, int $recordId, array $cursor, int $now): bool
    {
        if($sourceTime >= $now)
        {
            return false;
        }
        $cursorTime = (int)($cursor['cursor_time'] ?? 0);
        $cursorValue = (int)($cursor['cursor_value'] ?? 0);
        return $sourceTime > $cursorTime || ($sourceTime === $cursorTime && $recordId > $cursorValue);
    }
}
