<?php
namespace app\integration\wangdian;

use app\service\OrderAftersaleService;
use think\facade\Db;

trait WangDianAftersaleStoreWorkflow
{
    public function pendingAftersales(int $limit, int $now): array
    {
        $limit = max(1, $limit);
        $result = [];
        $seenAftersales = [];
        $pushedOrderExists = Db::name('WangdianSyncLog')->alias('opl')
            ->whereRaw('opl.order_id=o.id')
            ->where(['opl.business_type'=>'order_push', 'opl.status'=>1])
            ->field('1')
            ->limit(1)
            ->buildSql();

        $retryLogs = Db::name('WangdianSyncLog')->where([
            ['business_type', '=', 'aftersale_push'],
            ['status', 'in', [0, 2]],
            ['next_retry_time', '<=', $now],
        ])->field('id,business_key')->order('next_retry_time asc,id asc')->limit($limit*5)->select()->toArray();
        $retryLogIds = [];
        foreach($retryLogs as $retryLog)
        {
            if(preg_match('/^(\d+):/', (string)$retryLog['business_key'], $matches) === 1)
            {
                $retryLogIds[(int)$matches[1]][(string)$retryLog['business_key']][] = (int)$retryLog['id'];
            } else {
                Db::name('WangdianSyncLog')->where(['id'=>(int)$retryLog['id']])->update([
                    'next_retry_time'=>4294967295,
                    'last_error'=>'Invalid aftersale revision key; automatic retry retired.',
                    'upd_time'=>$now,
                ]);
            }
        }
        foreach($retryLogIds as $aftersaleId=>$logsByKey)
        {
            $rows = $this->aftersaleRows('oa.id', [$aftersaleId], $pushedOrderExists);
            if(empty($rows))
            {
                Db::name('WangdianSyncLog')->where(['id'=>array_merge(...array_values($logsByKey))])->update([
                    'next_retry_time'=>$now+self::AFTERSALE_RECHECK_SECONDS,
                    'upd_time'=>$now,
                ]);
                continue;
            }
            $currentKey = $this->aftersaleCandidate($rows[0])['key'];
            foreach($logsByKey as $loggedKey=>$logIds)
            {
                if($loggedKey !== $currentKey)
                {
                    Db::name('WangdianSyncLog')->where([
                        ['id', 'in', $logIds],
                        ['status', 'in', [0, 2]],
                    ])->update([
                        'next_retry_time'=>4294967295,
                        'last_error'=>'Superseded by a newer aftersale revision.',
                        'upd_time'=>$now,
                    ]);
                }
            }
            $this->appendAftersaleCandidates(
                $rows,
                $result,
                $seenAftersales,
                $limit,
                $now
            );
            if(count($result) >= $limit)
            {
                break;
            }
        }

        if(count($result) < $limit)
        {
            $recheckBefore = max(0, $now-self::AFTERSALE_RECHECK_SECONDS);
            $recheckLogs = Db::name('WangdianSyncLog')->where([
                ['business_type', '=', 'aftersale_push'],
                ['status', '=', 1],
                ['upd_time', '<=', $recheckBefore],
            ])->field('id,business_key')->order('upd_time asc,id asc')->limit(max(100, $limit*10))->select()->toArray();
            $recheckLogIds = [];
            foreach($recheckLogs as $recheckLog)
            {
                if(preg_match('/^(\d+):/', (string)$recheckLog['business_key'], $matches) === 1)
                {
                    $recheckLogIds[(int)$matches[1]][] = (int)$recheckLog['id'];
                } else {
                    Db::name('WangdianSyncLog')->where(['id'=>(int)$recheckLog['id'], 'status'=>1])->update(['upd_time'=>$now]);
                }
            }
            foreach($recheckLogIds as $aftersaleId=>$logIds)
            {
                $foundCandidate = false;
                $completed = $this->appendAftersaleCandidates(
                    $this->aftersaleRows('oa.id', [$aftersaleId], $pushedOrderExists),
                    $result,
                    $seenAftersales,
                    $limit,
                    $now,
                    $foundCandidate
                );
                if(!$completed)
                {
                    break;
                }
                if(!$foundCandidate)
                {
                    Db::name('WangdianSyncLog')->where(['id'=>$logIds, 'status'=>1])->update(['upd_time'=>$now]);
                }
            }
        }

        foreach([
            ['OrderAftersale', 'aftersale_push_changed', 'oa.id', 'id', [], 0],
            ['Order', 'aftersale_push_order_changed', 'oa.order_id', 'id', [], max(0, $now-300)],
            ['OrderDetail', 'aftersale_push_detail_changed', 'oa.order_detail_id', 'id', [], max(0, $now-300)],
            ['WangdianSyncLog', 'aftersale_push_order_pushed', 'oa.order_id', 'order_id', [
                'business_type'=>'order_push',
                'status'=>1,
            ], max(0, $now-300)],
        ] as [$sourceTable, $cursorName, $aftersaleLink, $sourceValueField, $sourceWhere, $initialTime])
        {
            if(count($result) >= $limit)
            {
                break;
            }
            $cursor = $this->syncCursor($cursorName, $now, $initialTime);
            $sourceRows = Db::name($sourceTable)
                ->where($sourceWhere)
                ->whereRaw(
                    '(upd_time > '.(int)$cursor['cursor_time']
                    .' OR (upd_time = '.(int)$cursor['cursor_time'].' AND id > '.(int)$cursor['cursor_value'].'))'
                )
                ->field($sourceValueField === 'id' ? 'id,upd_time' : 'id,upd_time,'.$sourceValueField)
                ->order('upd_time asc,id asc')
                ->limit(50)
                ->select()
                ->toArray();
            $completedSource = true;
            $lastSourceTime = (int)$cursor['cursor_time'];
            foreach($sourceRows as $sourceRow)
            {
                $foundCandidate = false;
                $completedParent = $this->appendAftersaleCandidates(
                    $this->aftersaleRows($aftersaleLink, [(int)$sourceRow[$sourceValueField]], $pushedOrderExists),
                    $result,
                    $seenAftersales,
                    $limit,
                    $now,
                    $foundCandidate
                );
                if(!$completedParent || $foundCandidate)
                {
                    $completedSource = false;
                    break;
                }
                $lastSourceTime = (int)$sourceRow['upd_time'];
                $this->saveSyncCursor($cursorName, (int)$sourceRow['id'], $lastSourceTime, $now);
            }
            if($completedSource && count($sourceRows) < 50)
            {
                $this->saveSyncCursor($cursorName, 0, max($now, $lastSourceTime), $now);
            }
        }

        if(count($result) < $limit)
        {
            $cursor = $this->syncCursor('aftersale_push_recheck', $now);
            if((int)$cursor['cursor_time'] <= $now)
            {
                $rows = $this->aftersaleWindowAfterId((int)$cursor['cursor_value'], 100, $pushedOrderExists);
                $lastCompletedId = (int)$cursor['cursor_value'];
                $foundCandidate = false;
                foreach($rows as $row)
                {
                    $rowFoundCandidate = false;
                    if(!$this->appendAftersaleCandidates(
                        [$row],
                        $result,
                        $seenAftersales,
                        $limit,
                        $now,
                        $rowFoundCandidate
                    ))
                    {
                        break;
                    }
                    if($rowFoundCandidate)
                    {
                        $foundCandidate = true;
                        break;
                    }
                    $lastCompletedId = (int)$row['id'];
                }
                if(!$foundCandidate)
                {
                    $finishedCycle = count($rows) < 100
                        && $lastCompletedId === (int)($rows[array_key_last($rows)]['id'] ?? $lastCompletedId);
                    $this->saveSyncCursor(
                        'aftersale_push_recheck',
                        $finishedCycle ? 0 : $lastCompletedId,
                        $finishedCycle ? $now+self::AFTERSALE_RECHECK_SECONDS : (int)$cursor['cursor_time'],
                        $now
                    );
                }
            }
        }
        return $result;
    }

    private function aftersaleRows(string $field, array $values, string $pushedOrderExists): array
    {
        if(empty($values))
        {
            return [];
        }
        return $this->aftersaleQuery($pushedOrderExists)
            ->where([$field=>$values])
            ->order('oa.id asc')
            ->limit(500)
            ->select()
            ->toArray();
    }

    private function aftersaleWindowAfterId(int $afterId, int $limit, string $pushedOrderExists): array
    {
        return $this->aftersaleQuery($pushedOrderExists)
            ->where([['oa.id', '>', $afterId]])
            ->order('oa.id asc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    private function aftersaleQuery(string $pushedOrderExists)
    {
        return Db::name('OrderAftersale')->alias('oa')
            ->join('order o', 'o.id=oa.order_id')
            ->join('order_detail od', 'od.id=oa.order_detail_id')
            ->where([
                ['o.order_model', '=', 0],
                ['oa.type', 'in', [0, 1]],
                ['oa.status', 'between', [0, 5]],
            ])
            ->whereRaw('EXISTS '.$pushedOrderExists)
            ->field('oa.*,o.id AS shopxo_order_id,o.order_no AS shopxo_order_no,o.user_id AS shopxo_user_id,o.delivery_time AS shopxo_delivery_time,od.id AS shopxo_detail_id,od.buy_number AS shopxo_buy_number');
    }

    private function appendAftersaleCandidates(
        array $rows,
        array &$result,
        array &$seenAftersales,
        int $limit,
        int $now,
        bool &$foundCandidate = false
    ): bool
    {
        foreach($rows as $row)
        {
            if(count($result) >= $limit)
            {
                return false;
            }
            $aftersaleId = (int)$row['id'];
            if(isset($seenAftersales[$aftersaleId]))
            {
                continue;
            }
            $seenAftersales[$aftersaleId] = true;
            $candidate = $this->aftersaleCandidate($row);
            $key = $candidate['key'];
            $log = Db::name('WangdianSyncLog')->where([
                'business_type'=>'aftersale_push',
                'business_key'=>$key,
            ])->find();
            if(empty($log) || (
                in_array((int)$log['status'], [0, 2], true)
                && (int)$log['next_retry_time'] <= $now
            ))
            {
                $result[] = $candidate;
                $foundCandidate = true;
            }
        }
        return true;
    }

    private function aftersaleCandidate(array $row): array
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
        return [
            'key'=>WangDianAftersaleRevision::key($row, $order, $detail),
            'aftersale'=>$row,
            'order'=>$order,
            'detail'=>$detail,
        ];
    }

    private function syncCursor(string $name, int $now, int $initialTime = 0): array
    {
        Db::name('WangdianSyncCursor')->extra('IGNORE')->insert([
            'name'=>$name,
            'cursor_value'=>0,
            'cursor_time'=>max(0, $initialTime),
            'add_time'=>$now,
            'upd_time'=>$now,
        ]);
        $cursor = Db::name('WangdianSyncCursor')->where(['name'=>$name])->find();
        if(empty($cursor))
        {
            throw new \RuntimeException('Failed to initialize Wangdiantong synchronization cursor '.$name.'.');
        }
        return $cursor;
    }

    private function saveSyncCursor(string $name, int $value, int $time, int $now): void
    {
        Db::name('WangdianSyncCursor')->where(['name'=>$name])->update([
            'cursor_value'=>max(0, $value),
            'cursor_time'=>max(0, $time),
            'upd_time'=>$now,
        ]);
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
