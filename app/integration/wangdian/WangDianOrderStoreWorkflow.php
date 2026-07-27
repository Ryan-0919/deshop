<?php
namespace app\integration\wangdian;

use think\facade\Db;

trait WangDianOrderStoreWorkflow
{
    public function pendingOrders(int $syncFrom, int $limit, int $now): array
    {
        $result = [];
        $lastOrderId = 0;
        $limit = max(1, $limit);
        $chunkSize = min(500, max(100, $limit*5));
        $sourceFields = WangDianOrderMapper::sourceFields();
        $latestSuccessfulOrderCheck = Db::name('WangdianSyncLog')->alias('woc2')
            ->whereRaw('woc2.order_id=o.id')
            ->where(['woc2.business_type'=>'order_push', 'woc2.status'=>1])
            ->field('woc2.id')
            ->order('woc2.upd_time desc,woc2.id desc')
            ->limit(1)
            ->buildSql();
        $changedDetails = Db::name('OrderDetail')->alias('odc')
            ->whereRaw('odc.order_id=o.id')
            ->whereRaw('GREATEST(odc.add_time,odc.upd_time)>woc.upd_time')
            ->field('1')
            ->limit(1)
            ->buildSql();
        $recheckBefore = max(0, $now-self::ORDER_RECHECK_SECONDS);
        while(count($result) < $limit)
        {
            $orders = Db::name('Order')->alias('o')
                ->leftJoin('order_address oa', 'oa.order_id=o.id')
                ->leftJoin('wangdian_sync_log woc', 'woc.id='.$latestSuccessfulOrderCheck)
                ->where([
                    ['o.id', '>', $lastOrderId],
                    ['o.order_model', '=', 0],
                    ['o.status', '=', 2],
                    ['o.pay_status', '=', 1],
                    ['o.pay_time', '>=', $syncFrom],
                    ['o.is_delete_time', '=', 0],
                ])
                ->whereRaw(
                    '(woc.order_id IS NULL'
                    .' OR GREATEST(COALESCE(o.add_time,0),COALESCE(o.upd_time,0),COALESCE(oa.add_time,0),COALESCE(oa.upd_time,0)) > woc.upd_time'
                    .' OR EXISTS '.$changedDetails
                    .' OR woc.upd_time <= '.$recheckBefore.')'
                )
                ->field($this->qualifiedFields($sourceFields['order'], 'o'))
                ->order('o.id asc')
                ->limit($chunkSize)
                ->select()
                ->toArray();
            if(empty($orders))
            {
                break;
            }
            $lastOrderId = (int)$orders[array_key_last($orders)]['id'];

            $orderIds = array_column($orders, 'id');
            $addressRows = Db::name('OrderAddress')
                ->where(['order_id'=>$orderIds])
                ->field(implode(',', $sourceFields['address']))
                ->select()
                ->toArray();
            $addresses = array_column($addressRows, null, 'order_id');
            $details = Db::name('OrderDetail')
                ->where(['order_id'=>$orderIds])
                ->field(implode(',', $sourceFields['detail']))
                ->order('id asc')
                ->select()
                ->toArray();
            $detailsByOrder = [];
            $skuCodes = [];
            foreach($details as $detail)
            {
                $detailsByOrder[$detail['order_id']][] = $detail;
                $sku = trim((string)($detail['spec_coding'] ?? ''));
                if($sku !== '')
                {
                    $skuCodes[] = $sku;
                }
            }

            $skuMatches = [];
            if(!empty($skuCodes))
            {
                $specifications = Db::name('GoodsSpecBase')
                    ->where(['coding'=>array_values(array_unique($skuCodes))])
                    ->field('id,goods_id,coding')
                    ->select()
                    ->toArray();
                foreach($specifications as $specification)
                {
                    $skuMatches[strtolower((string)$specification['coding'])][] = $specification;
                }
            }

            $candidates = [];
            foreach($orders as $order)
            {
                $orderId = (int)$order['id'];
                $orderDetails = $detailsByOrder[$orderId] ?? [];
                $address = $addresses[$orderId] ?? [];
                $key = WangDianOrderRevision::key($order, $address, $orderDetails);
                $candidates[$key] = [
                    'key'=>$key,
                    'order'=>$order,
                    'address'=>$address,
                    'details'=>$orderDetails,
                    'validation_error'=>$this->orderSkuValidationError($orderDetails, $skuMatches),
                ];
            }
            $logs = Db::name('WangdianSyncLog')->where([
                'business_type'=>'order_push',
                'business_key'=>array_keys($candidates),
            ])->column('*', 'business_key');
            $checkedKeys = [];
            foreach($candidates as $key=>$candidate)
            {
                $log = $logs[$key] ?? [];
                if(empty($log) || (
                    in_array((int)$log['status'], [0,2], true)
                    && (int)$log['next_retry_time'] <= $now
                ))
                {
                    $result[] = $candidate;
                    if(count($result) >= $limit)
                    {
                        break;
                    }
                } elseif((int)$log['status'] === 1) {
                    $checkedKeys[] = $key;
                }
            }
            if(!empty($checkedKeys))
            {
                Db::name('WangdianSyncLog')->where([
                    'business_type'=>'order_push',
                    'business_key'=>$checkedKeys,
                    'status'=>1,
                ])->update(['upd_time'=>$now]);
            }
            if(count($orders) < $chunkSize)
            {
                break;
            }
        }
        return $result;
    }

    private function orderSkuValidationError(array $details, array $skuMatches): string
    {
        foreach($details as $index=>$detail)
        {
            $sku = trim((string)($detail['spec_coding'] ?? ''));
            if($sku === '')
            {
                continue;
            }
            $matches = $skuMatches[strtolower($sku)] ?? [];
            if(count($matches) !== 1)
            {
                return 'SKU '.$sku.' for goods '.($detail['goods_id'] ?? 'unknown')
                    .', order detail '.($detail['id'] ?? $index).' must be unique in ShopXO.';
            }
            if((string)$matches[0]['coding'] !== $sku)
            {
                return 'SKU '.$sku.' does not exactly match ShopXO SKU '.$matches[0]['coding']
                    .' for order detail '.($detail['id'] ?? $index).'.';
            }
            if((int)$matches[0]['goods_id'] !== (int)($detail['goods_id'] ?? 0))
            {
                return 'SKU '.$sku.' does not belong to goods '.($detail['goods_id'] ?? 'unknown')
                    .', order detail '.($detail['id'] ?? $index).'.';
            }
        }
        return '';
    }

    private function qualifiedFields(array $fields, string $alias): string
    {
        return implode(',', array_map(
            static fn(string $field): string => $alias.'.'.$field,
            $fields
        ));
    }

}
