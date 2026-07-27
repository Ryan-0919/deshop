<?php
namespace app\service;

final class RefundRequestNoService
{
    public static function ForAftersale(array $aftersale): string
    {
        $aftersaleId = (int)($aftersale['id'] ?? 0);
        if($aftersaleId <= 0)
        {
            throw new \InvalidArgumentException('ShopXO aftersale id is required for a stable refund request number.');
        }
        return 'SHOPXO-AS-'.$aftersaleId;
    }

    public static function Provided(array $params): string
    {
        return trim((string)($params['refund_no'] ?? ''));
    }
}
