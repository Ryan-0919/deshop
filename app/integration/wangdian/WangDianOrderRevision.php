<?php
namespace app\integration\wangdian;

final class WangDianOrderRevision
{
    public static function key(array $order, array $address, array $details): string
    {
        $orderId = (int)($order['id'] ?? 0);
        if($orderId <= 0)
        {
            throw new \InvalidArgumentException('ShopXO order id is required for the Wangdiantong revision key.');
        }
        $payload = self::normalize([$order, $address, array_values($details)]);
        return $orderId.':'.hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    private static function normalize($value)
    {
        if(!is_array($value))
        {
            return $value;
        }
        if(self::isList($value))
        {
            return array_map([self::class, 'normalize'], $value);
        }

        ksort($value, SORT_STRING);
        foreach($value as $key=>$item)
        {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }

    private static function isList(array $value): bool
    {
        $expectedKey = 0;
        foreach($value as $key=>$unused)
        {
            if($key !== $expectedKey)
            {
                return false;
            }
            $expectedKey++;
        }
        return true;
    }
}
