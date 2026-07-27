<?php
namespace app\integration\wangdian;

final class WangDianInventoryValue
{
    private const MYSQL_UNSIGNED_INT_MAX = '4294967295';

    public static function parse($value): int
    {
        if(is_int($value))
        {
            if($value >= 0 && $value <= (int)self::MYSQL_UNSIGNED_INT_MAX)
            {
                return $value;
            }
            throw new \InvalidArgumentException('Wangdiantong inventory must fit a MySQL unsigned integer.');
        }
        if(!is_string($value) || preg_match('/^(?:0|[1-9]\d*)$/D', $value) !== 1)
        {
            throw new \InvalidArgumentException('Wangdiantong inventory must be a canonical non-negative integer.');
        }

        $max = self::MYSQL_UNSIGNED_INT_MAX;
        if(strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0))
        {
            throw new \InvalidArgumentException('Wangdiantong inventory exceeds the supported integer range.');
        }
        return (int)$value;
    }
}
