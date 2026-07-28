<?php
namespace app\integration\wangdian;

final class WangDianOrderMapper
{
    public static function sourceFields(): array
    {
        return [
            'order'=>[
                'id', 'order_no', 'user_id', 'status', 'pay_status', 'add_time', 'pay_time', 'user_note',
                'increase_price', 'pay_price', 'total_price', 'preferential_price',
                'refund_price', 'returned_quantity',
            ],
            'address'=>[
                'order_id', 'name', 'tel', 'province_name', 'city_name', 'county_name', 'address',
            ],
            'detail'=>[
                'id', 'order_id', 'goods_id', 'title', 'coding', 'spec_coding',
                'buy_number', 'price', 'total_price', 'refund_price', 'returned_quantity', 'spec',
            ],
        ];
    }

    public static function map(array $order, array $address, array $details): array
    {
        self::requireValue($order, 'order_no', 'ShopXO order number is required.');
        self::requireValue($address, 'name', 'Order receiver name is required.');
        self::requireValue($address, 'tel', 'Order receiver mobile is required.');
        self::requireValue($address, 'province_name', 'Order receiver province is required.');
        self::requireValue($address, 'city_name', 'Order receiver city is required.');
        self::requireValue($address, 'county_name', 'Order receiver district is required.');
        self::requireValue($address, 'address', 'Order receiver address is required.');
        if(empty($details))
        {
            throw new \InvalidArgumentException('ShopXO order details are required.');
        }

        $lineCents = [];
        foreach($details as $index => $detail)
        {
            $sku = trim((string)($detail['spec_coding'] ?? ''));
            if($sku === '')
            {
                throw new \InvalidArgumentException(
                    'SKU code is required for goods '.($detail['goods_id'] ?? 'unknown')
                    .', order detail '.($detail['id'] ?? $index).'.'
                );
            }
            $quantity = (int)($detail['buy_number'] ?? 0);
            if($quantity <= 0)
            {
                throw new \InvalidArgumentException('Order detail quantity must be greater than zero.');
            }
            $lineCents[$index] = self::moneyToCents($detail['total_price'] ?? ((float)($detail['price'] ?? 0) * $quantity));
        }

        $discountCents = max(0, self::moneyToCents($order['preferential_price'] ?? 0));
        $discounts = self::allocateDiscount($lineCents, $discountCents);
        $fullyRefunded = self::isFullyRefunded($order, $details);
        $orderList = [];
        foreach($details as $index => $detail)
        {
            $sku = trim((string)$detail['spec_coding']);
            $orderList[] = [
                'oid' => (string)$order['order_no'].'-'.(string)($detail['id'] ?? $index),
                'status' => $fullyRefunded ? 80 : 30,
                'refund_status' => $fullyRefunded ? 5 : 0,
                'goods_id' => (string)($detail['goods_id'] ?? ''),
                'spec_id' => $sku,
                'goods_no' => trim((string)($detail['coding'] ?? '')),
                'spec_no' => $sku,
                'goods_name' => self::cleanText((string)($detail['title'] ?? '')),
                'spec_name' => self::specName($detail['spec'] ?? ''),
                'num' => (int)$detail['buy_number'],
                'price' => self::formatMoney($detail['price'] ?? 0),
                'adjust_amount' => '0.00',
                'discount' => '0.00',
                'share_discount' => self::centsToMoney($discounts[$index] ?? 0),
            ];
        }

        $paid = array_key_exists('pay_price', $order)
            ? $order['pay_price']
            : ($order['total_price'] ?? 0);

        return [
            'tid' => (string)$order['order_no'],
            'trade_status' => $fullyRefunded ? 80 : 30,
            'pay_status' => 2,
            'delivery_term' => 1,
            'trade_time' => self::formatTimestamp((int)($order['add_time'] ?? 0)),
            'pay_time' => self::formatTimestamp((int)($order['pay_time'] ?? 0)),
            'buyer_nick' => 'shopxo_'.(string)($order['user_id'] ?? 0),
            'receiver_name' => self::cleanText((string)$address['name']),
            'receiver_province' => self::cleanText((string)$address['province_name']),
            'receiver_city' => self::cleanText((string)$address['city_name']),
            'receiver_district' => self::cleanText((string)$address['county_name']),
            'receiver_address' => self::cleanText((string)$address['address']),
            'receiver_mobile' => trim((string)$address['tel']),
            'buyer_message' => self::cleanText((string)($order['user_note'] ?? '')),
            'post_amount' => self::formatMoney($order['increase_price'] ?? 0),
            'paid' => self::formatMoney($paid),
            'cod_amount' => '0.00',
            'ext_cod_fee' => '0.00',
            'other_amount' => '0.00',
            'order_list' => $orderList,
        ];
    }

    private static function isFullyRefunded(array $order, array $details): bool
    {
        if((int)($order['status'] ?? 0) !== 6 || (int)($order['pay_status'] ?? 0) !== 2)
        {
            return false;
        }
        foreach($details as $detail)
        {
            $quantity = (int)($detail['buy_number'] ?? 0);
            if($quantity <= 0 || (int)($detail['returned_quantity'] ?? 0) < $quantity)
            {
                return false;
            }
        }
        return !empty($details);
    }

    private static function allocateDiscount(array $lineCents, int $discountCents): array
    {
        $result = [];
        $total = array_sum($lineCents);
        $remaining = $discountCents;
        $lastIndex = array_key_last($lineCents);
        foreach($lineCents as $index => $lineTotal)
        {
            $allocated = $index === $lastIndex || $total <= 0
                ? $remaining
                : (int)round($discountCents * ($lineTotal / $total));
            $allocated = min($remaining, max(0, $allocated));
            $result[$index] = $allocated;
            $remaining -= $allocated;
        }
        return $result;
    }

    private static function specName($spec): string
    {
        if(is_string($spec))
        {
            $decoded = json_decode($spec, true);
            $spec = is_array($decoded) ? $decoded : [];
        }
        if(!is_array($spec))
        {
            return '';
        }

        $values = [];
        foreach($spec as $item)
        {
            if(is_array($item))
            {
                $value = $item['value'] ?? $item['name'] ?? '';
                if($value !== '')
                {
                    $values[] = self::cleanText((string)$value);
                }
            } elseif($item !== '') {
                $values[] = self::cleanText((string)$item);
            }
        }
        return implode(' ', $values);
    }

    private static function formatTimestamp(int $timestamp): string
    {
        if($timestamp <= 0)
        {
            return '0000-00-00 00:00:00';
        }
        return (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
    }

    private static function cleanText(string $value): string
    {
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    }

    private static function requireValue(array $data, string $key, string $message): void
    {
        if(!isset($data[$key]) || trim((string)$data[$key]) === '')
        {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function moneyToCents($value): int
    {
        return (int)round((float)$value * 100);
    }

    private static function centsToMoney(int $value): string
    {
        return number_format($value / 100, 2, '.', '');
    }

    private static function formatMoney($value): string
    {
        return self::centsToMoney(self::moneyToCents($value));
    }
}
