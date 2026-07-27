<?php
namespace app\integration\wangdian;

use app\service\RefundRequestNoService;

final class WangDianAftersaleMapper
{
    private const STATUS_MAP = [
        0 => 'wait_seller_agree',
        1 => 'goods_returning',
        2 => 'goods_receiving',
        3 => 'success',
        4 => 'seller_refuse',
        5 => 'closed',
    ];

    public static function map(array $aftersale, array $order, array $detail, string $shopNo): array
    {
        $aftersaleId = (int)($aftersale['id'] ?? 0);
        $orderNo = trim((string)($order['order_no'] ?? ''));
        $detailId = (int)($detail['id'] ?? 0);
        $shopNo = trim($shopNo);
        if($aftersaleId <= 0 || $orderNo === '' || $detailId <= 0 || $shopNo === '')
        {
            throw new \InvalidArgumentException('ShopXO aftersale, order detail and Wangdiantong shop identifiers are required.');
        }

        $type = (int)($aftersale['type'] ?? -1);
        if(!in_array($type, [0, 1], true))
        {
            throw new \InvalidArgumentException('Unsupported ShopXO aftersale type '.$type.'.');
        }
        $status = (int)($aftersale['status'] ?? -1);
        if(!array_key_exists($status, self::STATUS_MAP))
        {
            throw new \InvalidArgumentException('Unsupported ShopXO aftersale status '.$status.'.');
        }

        $buyNumber = (int)($detail['buy_number'] ?? 0);
        $quantity = $type === 1 ? (int)($aftersale['number'] ?? 0) : $buyNumber;
        if($quantity <= 0 || ($buyNumber > 0 && $quantity > $buyNumber))
        {
            throw new \InvalidArgumentException('ShopXO aftersale quantity must be positive and no greater than the order line quantity.');
        }

        $mappedStatus = self::STATUS_MAP[$status];
        if($type === 0 && $status === 2)
        {
            $mappedStatus = 'wait_seller_agree';
        }
        $result = [
            'platform_id' => 127,
            'shop_no' => $shopNo,
            'tid' => $orderNo,
            'refund_no' => RefundRequestNoService::ForAftersale($aftersale),
            'type' => $type === 1 ? 3 : ((int)($order['delivery_time'] ?? 0) > 0 ? 2 : 1),
            'status' => $mappedStatus,
            'refund_fee' => self::formatMoney($aftersale['price'] ?? 0),
            'buyer_nick' => 'shopxo_'.(string)($order['user_id'] ?? 0),
            'refund_time' => self::formatTimestamp((int)($aftersale['apply_time'] ?? 0)),
            'reason' => self::truncate(self::cleanText((string)($aftersale['reason'] ?? '')), 255),
            'desc' => self::truncate(self::cleanText((string)($aftersale['msg'] ?? '')), 255),
            'order_list' => [[
                'oid' => $orderNo.'-'.$detailId,
                'num' => $quantity,
            ]],
        ];

        $logisticsNo = trim((string)($aftersale['express_number'] ?? ''));
        $logisticsName = self::truncate(self::cleanText((string)($aftersale['express_name'] ?? '')), 40);
        if($logisticsNo !== '' || $logisticsName !== '')
        {
            if($logisticsNo === '' || $logisticsName === '')
            {
                throw new \InvalidArgumentException('Return logistics number and company name must be provided together.');
            }
            $result['logistics_no'] = self::truncate($logisticsNo, 40);
            $result['logistics_name'] = $logisticsName;
        }
        return $result;
    }

    public static function assertRemoteLink(array $record, array $aftersale, array $order, array $detail): string
    {
        $orderNo = trim((string)($aftersale['order_no'] ?? ''));
        $detailId = (int)($aftersale['order_detail_id'] ?? 0);
        if($orderNo === '' || $detailId <= 0)
        {
            throw new \RuntimeException('ShopXO aftersale order linkage is incomplete.');
        }
        if(trim((string)($record['tid'] ?? '')) !== $orderNo)
        {
            throw new \RuntimeException('ERP refund belongs to an unexpected ShopXO order.');
        }

        $localType = (int)($aftersale['type'] ?? -1);
        $expectedType = $localType === 1 ? 2 : ((int)($order['delivery_time'] ?? 0) > 0 ? 4 : 1);
        if(!in_array($localType, [0, 1], true) || (int)($record['type'] ?? 0) !== $expectedType)
        {
            throw new \RuntimeException('ERP refund type does not match the ShopXO aftersale.');
        }
        $action = WangDianAftersaleStatePolicy::action($record['process_status'] ?? null);
        $localAmount = self::moneyToCents($aftersale['price'] ?? null);
        $remoteAmount = array_key_exists('refund_amount', $record)
            ? self::moneyToCents($record['refund_amount'])
            : null;
        if($localAmount === null || $remoteAmount === null || $remoteAmount !== $localAmount)
        {
            throw new \RuntimeException('ERP refund amount does not match the ShopXO aftersale.');
        }
        if($action === 'complete')
        {
            $actualAmount = array_key_exists('actual_refund_amount', $record)
                ? self::moneyToCents($record['actual_refund_amount'])
                : null;
            if($actualAmount === null || $actualAmount !== $localAmount)
            {
                throw new \RuntimeException('ERP actual refund amount does not match the completed ShopXO aftersale.');
            }
        }

        $lines = $record['refund_order_list'] ?? null;
        if(!is_array($lines) || empty($lines))
        {
            throw new \RuntimeException('ERP refund does not contain order-detail linkage.');
        }
        $expectedOid = $orderNo.'-'.$detailId;
        if(count($lines) !== 1 || !is_array($lines[0]) || trim((string)($lines[0]['oid'] ?? '')) !== $expectedOid)
        {
            throw new \RuntimeException('ERP refund is linked to an unexpected ShopXO order detail.');
        }
        if($action === 'complete')
        {
            $expectedQuantity = $localType === 1
                ? (int)($aftersale['number'] ?? 0)
                : (int)($detail['buy_number'] ?? 0);
            $refundQuantity = $lines[0]['refund_num'] ?? null;
            $refundQuantityText = is_int($refundQuantity) || is_float($refundQuantity) || is_string($refundQuantity)
                ? trim((string)$refundQuantity)
                : '';
            if($expectedQuantity <= 0
                || preg_match('/^\d+(?:\.0+)?$/D', $refundQuantityText) !== 1
                || (int)$refundQuantityText !== $expectedQuantity)
            {
                throw new \RuntimeException('ERP refund quantity does not match the completed ShopXO aftersale.');
            }
        }
        return $action;
    }

    private static function formatTimestamp(int $timestamp): string
    {
        if($timestamp <= 0)
        {
            throw new \InvalidArgumentException('ShopXO aftersale apply time is required.');
        }
        return (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
    }

    private static function formatMoney($value): string
    {
        $cents = self::moneyToCents($value);
        if($cents === null)
        {
            throw new \InvalidArgumentException('ShopXO aftersale refund amount must be a valid decimal value.');
        }
        return number_format($cents / 100, 2, '.', '');
    }

    private static function moneyToCents($value): ?int
    {
        if(!is_int($value) && !is_float($value) && !is_string($value))
        {
            return null;
        }
        $text = trim((string)$value);
        if(preg_match('/^(\d+)(?:\.(\d+))?$/D', $text, $matches) !== 1)
        {
            return null;
        }

        $fraction = $matches[2] ?? '';
        if(strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '')
        {
            return null;
        }
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fractionCents = (int)$fraction;
        if(strlen($whole) > strlen((string)PHP_INT_MAX))
        {
            return null;
        }
        $wholeValue = (int)$whole;
        if($wholeValue > intdiv(PHP_INT_MAX - $fractionCents, 100))
        {
            return null;
        }
        return ($wholeValue * 100) + $fractionCents;
    }

    private static function cleanText(string $value): string
    {
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    }

    private static function truncate(string $value, int $maxCharacters): string
    {
        if(preg_match('/^.{0,'.$maxCharacters.'}/us', $value, $matches) === 1)
        {
            return $matches[0];
        }
        return '';
    }
}
