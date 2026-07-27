<?php
namespace app\integration\wangdian;

use app\service\GoodsService;
use app\service\OrderAftersaleService;
use app\service\OrderService;
use think\facade\Db;

final class WangDianStore implements WangDianStoreInterface
{
    private const PROCESSING_LEASE_SECONDS = 300;
    private const MAX_RETRY_SECONDS = 3600;

    public function pendingOrders(int $syncFrom, int $limit, int $now): array
    {
        $orders = Db::name('Order')->alias('o')
            ->leftJoin('wangdian_sync_log wsl', "wsl.business_type='order_push' AND wsl.business_key=CAST(o.id AS CHAR)")
            ->where([
                ['o.order_model', '=', 0],
                ['o.status', '=', 2],
                ['o.pay_status', '=', 1],
                ['o.pay_time', '>=', $syncFrom],
                ['o.is_delete_time', '=', 0],
            ])
            ->whereRaw('(wsl.id IS NULL OR (wsl.status IN (0,2) AND wsl.next_retry_time <= '.(int)$now.'))')
            ->field('o.*')
            ->order('o.id asc')
            ->limit($limit)
            ->select()
            ->toArray();
        if(empty($orders))
        {
            return [];
        }

        $orderIds = array_column($orders, 'id');
        $addresses = Db::name('OrderAddress')->where(['order_id'=>$orderIds])->column('*', 'order_id');
        $details = Db::name('OrderDetail')->where(['order_id'=>$orderIds])->order('id asc')->select()->toArray();
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

        $result = [];
        foreach($orders as $order)
        {
            $orderId = (int)$order['id'];
            $orderDetails = $detailsByOrder[$orderId] ?? [];
            $result[] = [
                'order'=>$order,
                'address'=>$addresses[$orderId] ?? [],
                'details'=>$orderDetails,
                'validation_error'=>$this->orderSkuValidationError($orderDetails, $skuMatches),
            ];
        }
        return $result;
    }

    public function pendingAftersales(int $limit, int $now): array
    {
        $eventTime = "CASE oa.status"
            ." WHEN 0 THEN oa.apply_time"
            ." WHEN 1 THEN oa.confirm_time"
            ." WHEN 2 THEN IF(oa.type=1, oa.delivery_time, oa.apply_time)"
            ." WHEN 3 THEN oa.audit_time"
            ." WHEN 4 THEN oa.audit_time"
            ." WHEN 5 THEN oa.cancel_time"
            ." ELSE oa.upd_time END";
        $syncKey = "CONCAT(oa.id, ':', oa.status, ':', ".$eventTime.")";
        $rows = Db::name('OrderAftersale')->alias('oa')
            ->join('order o', 'o.id=oa.order_id')
            ->join('order_detail od', 'od.id=oa.order_detail_id')
            ->join('wangdian_sync_log opl', "opl.business_type='order_push' AND opl.business_key=CAST(o.id AS CHAR) AND opl.status=1")
            ->leftJoin('wangdian_sync_log wsl', "wsl.business_type='aftersale_push' AND wsl.business_key=".$syncKey)
            ->where([
                ['o.order_model', '=', 0],
                ['oa.type', 'in', [0, 1]],
                ['oa.status', 'between', [0, 5]],
            ])
            ->whereRaw('(wsl.id IS NULL OR (wsl.status IN (0,2) AND wsl.next_retry_time <= '.(int)$now.'))')
            ->field('oa.*,'.$syncKey.' AS sync_key,o.id AS shopxo_order_id,o.order_no AS shopxo_order_no,o.user_id AS shopxo_user_id,o.delivery_time AS shopxo_delivery_time,od.id AS shopxo_detail_id,od.buy_number AS shopxo_buy_number')
            ->order('oa.id asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $result = [];
        foreach($rows as $row)
        {
            $result[] = [
                'key'=>(string)$row['sync_key'],
                'aftersale'=>$row,
                'order'=>[
                    'id'=>(int)$row['shopxo_order_id'],
                    'order_no'=>(string)$row['shopxo_order_no'],
                    'user_id'=>(int)$row['shopxo_user_id'],
                    'delivery_time'=>(int)$row['shopxo_delivery_time'],
                ],
                'detail'=>[
                    'id'=>(int)$row['shopxo_detail_id'],
                    'buy_number'=>(int)$row['shopxo_buy_number'],
                ],
            ];
        }
        return $result;
    }

    public function startAttempt(string $businessType, string $businessKey, int $orderId, array $requestSummary, int $now): bool
    {
        Db::startTrans();
        try {
            $where = ['business_type'=>$businessType, 'business_key'=>$businessKey];
            $log = Db::name('WangdianSyncLog')->where($where)->lock(true)->find();
            if(!empty($log) && (int)$log['status'] === 1)
            {
                Db::commit();
                return false;
            }
            if(!empty($log) && in_array((int)$log['status'], [0,2], true) && (int)$log['next_retry_time'] > $now)
            {
                Db::commit();
                return false;
            }

            $data = [
                'order_id'=>$orderId,
                'status'=>0,
                'request_payload'=>$this->encodeSummary($requestSummary),
                'response_payload'=>null,
                'last_error'=>'',
                'next_retry_time'=>$now+self::PROCESSING_LEASE_SECONDS,
                'last_attempt_time'=>$now,
                'upd_time'=>$now,
            ];
            if(empty($log))
            {
                $data['business_type'] = $businessType;
                $data['business_key'] = $businessKey;
                $data['attempts'] = 1;
                $data['add_time'] = $now;
                Db::name('WangdianSyncLog')->insert($data);
            } else {
                $data['attempts'] = (int)$log['attempts']+1;
                Db::name('WangdianSyncLog')->where(['id'=>$log['id']])->update($data);
            }
            Db::commit();
            return true;
        } catch(\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function markSuccess(string $businessType, string $businessKey, array $responseSummary, int $now): void
    {
        Db::name('WangdianSyncLog')->where([
            'business_type'=>$businessType,
            'business_key'=>$businessKey,
        ])->update([
            'status'=>1,
            'response_payload'=>$this->encodeSummary($responseSummary),
            'last_error'=>'',
            'next_retry_time'=>0,
            'synced_time'=>$now,
            'upd_time'=>$now,
        ]);
    }

    public function markFailure(
        string $businessType,
        string $businessKey,
        string $error,
        int $now,
        array $responseSummary = []
    ): void
    {
        $where = ['business_type'=>$businessType, 'business_key'=>$businessKey];
        $attempts = max(1, (int)Db::name('WangdianSyncLog')->where($where)->value('attempts'));
        $retrySeconds = min(self::MAX_RETRY_SECONDS, 60 * (2 ** min(6, $attempts-1)));
        $data = [
            'status'=>2,
            'last_error'=>$this->truncate($error, 1000),
            'next_retry_time'=>$now+$retrySeconds,
            'upd_time'=>$now,
        ];
        if(!empty($responseSummary))
        {
            $data['response_payload'] = $this->encodeSummary($responseSummary);
        }
        Db::name('WangdianSyncLog')->where($where)->update($data);
    }

    public function applyLogistics(array $record, array $expressMap): int
    {
        $orderNo = trim((string)($record['tid'] ?? ''));
        $expressNumber = trim((string)($record['logistics_no'] ?? ''));
        $expressCode = trim((string)($record['logistics_code_erp'] ?? ''));
        if($orderNo === '' || $expressNumber === '' || $expressCode === '')
        {
            throw new \InvalidArgumentException('The logistics record is missing tid, logistics_no or logistics_code_erp.');
        }

        $normalizedMap = [];
        foreach($expressMap as $code=>$id)
        {
            $normalizedMap[strtoupper(trim((string)$code))] = (int)$id;
        }
        $expressId = $normalizedMap[strtoupper($expressCode)] ?? 0;
        if($expressId <= 0)
        {
            throw new \RuntimeException('No ShopXO express mapping exists for ERP code '.$expressCode.'.');
        }

        $order = Db::name('Order')->where(['order_no'=>$orderNo])->field('id,status,user_id')->find();
        if(empty($order))
        {
            throw new \RuntimeException('ShopXO order '.$orderNo.' was not found.');
        }
        $existing = Db::name('OrderExpress')->where(['order_id'=>$order['id']])->order('id asc')->find();
        if(!empty($existing) && (int)$existing['express_id'] === $expressId && trim((string)$existing['express_number']) === $expressNumber)
        {
            return (int)$order['id'];
        }

        $params = [
            'id'=>(int)$order['id'],
            'user_id'=>(int)$order['user_id'],
            'user_type'=>'admin',
            'express_id'=>$expressId,
            'express_number'=>$expressNumber,
            'creator'=>0,
            'creator_name'=>'Wangdiantong ERP',
        ];
        if(!empty($existing))
        {
            $params['order_express_id'] = (int)$existing['id'];
        }
        $result = OrderService::OrderDelivery($params);
        if((int)($result['code'] ?? -1) !== 0)
        {
            throw new \RuntimeException((string)($result['msg'] ?? 'ShopXO order delivery failed.'));
        }
        return (int)$order['id'];
    }

    public function applyInventory(array $record, int $warehouseId): int
    {
        $specNo = trim((string)($record['spec_no'] ?? ''));
        $externalGoodsId = trim((string)($record['goods_id'] ?? ''));
        if($specNo === '' || $externalGoodsId === '' || !isset($record['sync_stock']))
        {
            throw new \InvalidArgumentException('The inventory record is missing spec_no, goods_id or sync_stock.');
        }
        $inventory = (int)$record['sync_stock'];
        if($inventory < 0)
        {
            throw new \InvalidArgumentException('Wangdiantong sellable inventory cannot be negative.');
        }

        $matches = array_values(array_filter(
            Db::name('GoodsSpecBase')->where(['coding'=>$specNo])->select()->toArray(),
            static fn(array $item): bool => (string)$item['coding'] === $specNo
        ));
        if(count($matches) !== 1)
        {
            throw new \RuntimeException('SKU '.$specNo.' must match exactly one ShopXO specification.');
        }
        $specBase = $matches[0];
        $goodsId = (int)$specBase['goods_id'];
        if($externalGoodsId !== (string)$goodsId)
        {
            throw new \RuntimeException('SKU '.$specNo.' belongs to ShopXO goods '.$goodsId.', not '.$externalGoodsId.'.');
        }

        Db::startTrans();
        try {
            if(Db::name('GoodsSpecBase')->where(['id'=>$specBase['id'], 'goods_id'=>$goodsId])->update(['inventory'=>$inventory]) === false)
            {
                throw new \RuntimeException('Failed to update ShopXO SKU inventory.');
            }

            if($warehouseId > 0)
            {
                $this->updateWarehouseInventory($warehouseId, $goodsId, (int)$specBase['id'], $inventory);
            }

            $goodsInventory = (int)Db::name('GoodsSpecBase')->where(['goods_id'=>$goodsId])->sum('inventory');
            if(Db::name('Goods')->where(['id'=>$goodsId])->update(['inventory'=>$goodsInventory, 'upd_time'=>time()]) === false)
            {
                throw new \RuntimeException('Failed to update ShopXO goods inventory.');
            }
            Db::commit();
            return $goodsId;
        } catch(\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function applyAftersaleStatus(array $record, bool $autoProcess, int $refundment): int
    {
        $sourceNo = trim((string)($record['src_no'] ?? ''));
        if($sourceNo === '')
        {
            $sourceNo = trim((string)($record['api_outer_no'] ?? ''));
        }
        if(preg_match('/^SHOPXO-AS-(\d+)$/', $sourceNo, $matches) !== 1)
        {
            throw new \InvalidArgumentException('The ERP refund is not linked to a ShopXO aftersale.');
        }
        $aftersaleId = (int)$matches[1];
        $aftersale = Db::name('OrderAftersale')->where(['id'=>$aftersaleId])->find();
        if(empty($aftersale))
        {
            throw new \RuntimeException('ShopXO aftersale '.$aftersaleId.' was not found.');
        }
        $order = Db::name('Order')->where(['id'=>$aftersale['order_id']])->field('id,delivery_time')->find();
        if(empty($order))
        {
            throw new \RuntimeException('ShopXO order for aftersale '.$aftersaleId.' was not found.');
        }
        $detail = Db::name('OrderDetail')->where(['id'=>$aftersale['order_detail_id']])->field('id,buy_number')->find();
        if(empty($detail))
        {
            throw new \RuntimeException('ShopXO order detail for aftersale '.$aftersaleId.' was not found.');
        }
        $wasPushed = (int)Db::name('WangdianSyncLog')->where([
            ['business_type', '=', 'aftersale_push'],
            ['business_key', 'like', $aftersaleId.':%'],
            ['status', '=', 1],
        ])->count() > 0;
        if(!$wasPushed)
        {
            throw new \RuntimeException('ShopXO aftersale '.$aftersaleId.' has no successful Wangdiantong push record.');
        }
        WangDianAftersaleMapper::assertRemoteLink($record, $aftersale, $order, $detail);
        $processStatus = (int)($record['process_status'] ?? 0);
        $action = WangDianAftersaleStatePolicy::action($processStatus);
        if(!$autoProcess)
        {
            return $aftersaleId;
        }

        $localStatus = (int)$aftersale['status'];
        if($action === 'cancel')
        {
            if(!in_array($localStatus, [3, 5], true))
            {
                $this->assertAftersaleOperation(OrderAftersaleService::AftersaleCancel([
                    'id'=>$aftersaleId,
                    'creator'=>0,
                    'creator_name'=>'Wangdiantong ERP',
                ]));
            }
            return $aftersaleId;
        }
        if($action === 'refuse')
        {
            if(in_array($localStatus, [0, 2], true))
            {
                $reason = trim((string)($record['refund_reason'] ?? $record['remark'] ?? ''));
                $this->assertAftersaleOperation(OrderAftersaleService::AftersaleRefuse([
                    'id'=>$aftersaleId,
                    'refuse_reason'=>$reason === '' ? '旺店通 ERP 拒绝售后申请' : $this->truncate($reason, 230),
                    'creator'=>0,
                    'creator_name'=>'Wangdiantong ERP',
                ]));
            } elseif(!in_array($localStatus, [4, 5], true)) {
                throw new \RuntimeException('ERP rejected aftersale '.$aftersaleId.', but its ShopXO status requires manual handling.');
            }
            return $aftersaleId;
        }

        if($action === 'complete')
        {
            if($localStatus === 2)
            {
                $this->assertAftersaleOperation(OrderAftersaleService::AftersaleAudit([
                    'id'=>$aftersaleId,
                    'refundment'=>$refundment,
                    'is_under_line_use_manual'=>1,
                    'creator'=>0,
                    'creator_name'=>'Wangdiantong ERP',
                ]));
            } elseif($localStatus !== 3) {
                throw new \RuntimeException('ERP completed aftersale '.$aftersaleId.', but ShopXO is not ready to refund it.');
            }
            return $aftersaleId;
        }

        if((int)$aftersale['type'] === 1 && $localStatus === 0 && $action === 'approve')
        {
            $this->assertAftersaleOperation(OrderAftersaleService::AftersaleConfirm([
                'id'=>$aftersaleId,
                'creator'=>0,
                'creator_name'=>'Wangdiantong ERP',
            ]));
            $localStatus = 1;
        }
        return $aftersaleId;
    }

    private function updateWarehouseInventory(int $warehouseId, int $goodsId, int $specBaseId, int $inventory): void
    {
        $warehouseGoods = Db::name('WarehouseGoods')->where([
            'warehouse_id'=>$warehouseId,
            'goods_id'=>$goodsId,
        ])->find();
        if(empty($warehouseGoods))
        {
            throw new \RuntimeException('ShopXO warehouse '.$warehouseId.' is not configured for goods '.$goodsId.'.');
        }

        $actual = GoodsService::GoodsSpecificationsActual($goodsId);
        $specValue = 'default';
        foreach((array)($actual['value'] ?? []) as $item)
        {
            if((int)($item['base_id'] ?? 0) === $specBaseId)
            {
                $specValue = (string)($item['value'] ?? 'default');
                break;
            }
        }
        $md5Key = md5($specValue === 'default' ? 'default' : str_replace(GoodsService::$goods_spec_to_string_separator, '', $specValue));
        $warehouseSpec = Db::name('WarehouseGoodsSpec')->where([
            'warehouse_goods_id'=>$warehouseGoods['id'],
            'warehouse_id'=>$warehouseId,
            'goods_id'=>$goodsId,
            'md5_key'=>$md5Key,
        ])->find();
        if(empty($warehouseSpec))
        {
            throw new \RuntimeException('ShopXO warehouse specification was not found for SKU '.$specBaseId.'.');
        }
        if(Db::name('WarehouseGoodsSpec')->where(['id'=>$warehouseSpec['id']])->update(['inventory'=>$inventory]) === false)
        {
            throw new \RuntimeException('Failed to update ShopXO warehouse SKU inventory.');
        }

        $warehouseInventory = (int)Db::name('WarehouseGoodsSpec')->where(['warehouse_goods_id'=>$warehouseGoods['id']])->sum('inventory');
        if(Db::name('WarehouseGoods')->where(['id'=>$warehouseGoods['id']])->update([
            'inventory'=>$warehouseInventory,
            'upd_time'=>time(),
        ]) === false)
        {
            throw new \RuntimeException('Failed to update ShopXO warehouse goods inventory.');
        }
    }

    private function encodeSummary(array $summary): string
    {
        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->truncate($encoded === false ? '{}' : $encoded, 32000);
    }

    private function assertAftersaleOperation(array $result): void
    {
        if((int)($result['code'] ?? -1) !== 0)
        {
            throw new \RuntimeException((string)($result['msg'] ?? 'ShopXO aftersale operation failed.'));
        }
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

    private function truncate(string $value, int $maxCharacters): string
    {
        if(preg_match('/^.{0,'.$maxCharacters.'}/us', $value, $matches) === 1)
        {
            return $matches[0];
        }
        return '';
    }
}
