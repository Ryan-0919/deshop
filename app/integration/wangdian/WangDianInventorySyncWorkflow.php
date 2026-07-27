<?php
namespace app\integration\wangdian;

trait WangDianInventorySyncWorkflow
{
    public function syncInventory(): array
    {
        $result = $this->result();
        if(!$this->isEnabled())
        {
            return $result;
        }

        try {
            $this->assertShopNumber();
            $response = $this->client()->request('api_goods_stock_change_query.php', [
                'limit' => (string)min(2000, max(100, (int)($this->config['inventory_batch_size'] ?? 100))),
                'shop_no' => (string)$this->config['shop_no'],
            ]);
            $records = $response['stock_change_list'] ?? [];
            if(!is_array($records))
            {
                throw new \RuntimeException('Wangdiantong inventory response did not contain a valid stock change list.');
            }

            $processed = [];
            foreach($records as $record)
            {
                $recId = trim((string)($record['rec_id'] ?? ''));
                $changeCount = trim((string)($record['stock_change_count'] ?? ''));
                if($recId === '' || $changeCount === '')
                {
                    $result['failed']++;
                    continue;
                }
                $businessKey = $recId.':'.$changeCount;
                $attemptTime = $this->now();
                if(!$this->store()->startAttempt('inventory', $businessKey, 0, [
                    'rec_id'=>$recId,
                    'spec_no'=>(string)($record['spec_no'] ?? ''),
                    'stock_change_count'=>$changeCount,
                ], $attemptTime))
                {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $normalizedRecord = $record;
                    $normalizedRecord['sync_stock'] = WangDianInventoryValue::parse($record['sync_stock'] ?? null);
                    $goodsId = $this->store()->applyInventory($normalizedRecord, max(0, (int)($this->config['shopxo_warehouse_id'] ?? 0)));
                    $processed[] = [
                        'key'=>$businessKey,
                        'rec_id'=>$recId,
                        'goods_id'=>$goodsId,
                        'sync_stock'=>$normalizedRecord['sync_stock'],
                        'stock_change_count'=>$changeCount,
                        'attempt_time'=>$attemptTime,
                    ];
                } catch(\Throwable $e) {
                    $this->store()->markFailure('inventory', $businessKey, $this->safeError($e), $attemptTime);
                    $result['failed']++;
                }
            }

            if(empty($processed))
            {
                return $result;
            }

            $ack = array_map(static fn(array $item): array => [
                'rec_id'=>$item['rec_id'],
                'sync_stock'=>$item['sync_stock'],
                'stock_change_count'=>$item['stock_change_count'],
            ], $processed);
            try {
                $ackResponse = $this->client()->request('api_goods_stock_change_ack.php', [
                    'stock_sync_list' => json_encode($ack, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
                $ackErrors = $this->ackErrors($ackResponse, array_column($processed, 'rec_id'));
                foreach($processed as $item)
                {
                    $ackError = $ackErrors[$item['rec_id']] ?? $ackErrors['*'] ?? '';
                    if($ackError !== '')
                    {
                        $this->store()->markFailure(
                            'inventory',
                            $item['key'],
                            $ackError,
                            $item['attempt_time'],
                            $this->responseSummary($ackResponse)
                        );
                        $result['failed']++;
                    } else {
                        $this->store()->markSuccess('inventory', $item['key'], $this->responseSummary($ackResponse), $item['attempt_time']);
                        $result['success']++;
                    }
                }
            } catch(\Throwable $e) {
                foreach($processed as $item)
                {
                    $this->store()->markFailure(
                        'inventory',
                        $item['key'],
                        $this->safeError($e),
                        $item['attempt_time'],
                        $this->exceptionResponseSummary($e)
                    );
                    $result['failed']++;
                }
                $result['message'] = $this->safeError($e);
            }
        } catch(\Throwable $e) {
            $result['failed']++;
            $result['message'] = $this->safeError($e);
        }
        return $result;
    }

}
