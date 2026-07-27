<?php
namespace app\integration\wangdian;

use app\service\OrderAftersaleService;
use think\facade\Db;

trait WangDianAftersaleStoreWorkflow
{
    public function pendingAftersales(int $limit, int $now): array
    {
        $limit = max(1, $limit);
        $chunkSize = min(500, max(100, $limit*5));
        $lastAftersaleId = 0;
        $result = [];
        $pushedOrders = Db::name('WangdianSyncLog')
            ->where(['business_type'=>'order_push', 'status'=>1])
            ->field('order_id')
            ->group('order_id')
            ->buildSql();
        while(count($result) < $limit)
        {
            $rows = Db::name('OrderAftersale')->alias('oa')
                ->join('order o', 'o.id=oa.order_id')
                ->join('order_detail od', 'od.id=oa.order_detail_id')
                ->join([$pushedOrders=>'opl'], 'opl.order_id=o.id')
                ->where([
                    ['oa.id', '>', $lastAftersaleId],
                    ['o.order_model', '=', 0],
                    ['oa.type', 'in', [0, 1]],
                    ['oa.status', 'between', [0, 5]],
                ])
                ->field('oa.*,o.id AS shopxo_order_id,o.order_no AS shopxo_order_no,o.user_id AS shopxo_user_id,o.delivery_time AS shopxo_delivery_time,od.id AS shopxo_detail_id,od.buy_number AS shopxo_buy_number')
                ->order('oa.id asc')
                ->limit($chunkSize)
                ->select()
                ->toArray();
            if(empty($rows))
            {
                break;
            }
            $lastAftersaleId = (int)$rows[array_key_last($rows)]['id'];
            $candidates = [];
            foreach($rows as $row)
            {
                $order = [
                    'id'=>(int)$row['shopxo_order_id'],
                    'order_no'=>(string)$row['shopxo_order_no'],
                    'user_id'=>(int)$row['shopxo_user_id'],
                    'delivery_time'=>(int)$row['shopxo_delivery_time'],
                ];
                $detail = [
                    'id'=>(int)$row['shopxo_detail_id'],
                    'buy_number'=>(int)$row['shopxo_buy_number'],
                ];
                $key = WangDianAftersaleRevision::key($row, $order, $detail);
                $candidates[$key] = [
                    'key'=>$key,
                    'aftersale'=>$row,
                    'order'=>$order,
                    'detail'=>$detail,
                ];
            }
            $logs = Db::name('WangdianSyncLog')->where([
                'business_type'=>'aftersale_push',
                'business_key'=>array_keys($candidates),
            ])->column('*', 'business_key');
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
                }
            }
            if(count($rows) < $chunkSize)
            {
                break;
            }
        }
        return $result;
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
        $action = WangDianAftersaleMapper::validateRemoteLinkAndGetAction($record, $aftersale, $order, $detail);
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

    private function assertAftersaleOperation(array $result): void
    {
        if((int)($result['code'] ?? -1) !== 0)
        {
            throw new \RuntimeException((string)($result['msg'] ?? 'ShopXO aftersale operation failed.'));
        }
    }

}
