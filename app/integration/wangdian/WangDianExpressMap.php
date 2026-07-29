<?php
namespace app\integration\wangdian;

final class WangDianExpressMap
{
    public static function resolve(array $expressMap, string $expressCode): int
    {
        $normalizedMap = [];
        foreach($expressMap as $code=>$id)
        {
            $normalizedMap[strtoupper(trim((string)$code))] = (int)$id;
        }

        $expressId = $normalizedMap[strtoupper(trim($expressCode))] ?? 0;
        if($expressId <= 0)
        {
            throw new \RuntimeException('No ShopXO express mapping exists for ERP code '.$expressCode.'.');
        }
        return $expressId;
    }
}
