<?php
namespace app\integration\wangdian;

final class WangDianAftersaleRevision
{
    public static function key(array $aftersale, array $order, array $detail): string
    {
        $aftersaleId = (int)($aftersale['id'] ?? 0);
        if($aftersaleId <= 0)
        {
            throw new \InvalidArgumentException('ShopXO aftersale id is required for the Wangdiantong revision key.');
        }
        $payload = [
            self::only($aftersale, [
                'id', 'type', 'status', 'price', 'number', 'reason', 'msg',
                'express_name', 'express_number', 'apply_time',
            ]),
            self::only($order, ['id', 'order_no', 'user_id', 'delivery_time']),
            self::only($detail, ['id', 'buy_number']),
        ];
        return $aftersaleId.':'.hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    private static function only(array $source, array $fields): array
    {
        $result = [];
        foreach($fields as $field)
        {
            $result[$field] = $source[$field] ?? null;
        }
        return $result;
    }
}
