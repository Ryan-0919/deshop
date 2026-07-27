<?php
namespace app\integration\wangdian;

trait WangDianLogisticsSyncWorkflow
{
    public function syncLogistics(): array
    {
        $result = $this->result();
        if(!$this->isEnabled())
        {
            return $result;
        }

        try {
            $this->assertShopNumber();
            $now = $this->now();
            $response = $this->client()->request('logistics_sync_query.php', [
                'limit' => (string)min(100, max(1, (int)($this->config['logistics_batch_size'] ?? 100))),
                'shop_no' => (string)$this->config['shop_no'],
                'is_part_sync_able' => '0',
            ]);
            $records = $response['trades'] ?? [];
            if(!is_array($records))
            {
                throw new \RuntimeException('Wangdiantong logistics response did not contain a valid trades list.');
            }

            $processed = [];
            foreach($records as $record)
            {
                $recId = trim((string)($record['rec_id'] ?? ''));
                if($recId === '')
                {
                    $result['failed']++;
                    continue;
                }
                if(!$this->store()->startAttempt('logistics', $recId, 0, [
                    'rec_id'=>$recId,
                    'order_no'=>(string)($record['tid'] ?? ''),
                    'express_code'=>(string)($record['logistics_code_erp'] ?? ''),
                ], $now))
                {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $orderId = $this->store()->applyLogistics($record, (array)($this->config['express_map'] ?? []));
                    $processed[] = ['rec_id'=>$recId, 'success'=>true, 'order_id'=>$orderId, 'error'=>''];
                } catch(\Throwable $e) {
                    $error = $this->safeError($e);
                    $this->store()->markFailure('logistics', $recId, $error, $now);
                    $processed[] = ['rec_id'=>$recId, 'success'=>false, 'order_id'=>0, 'error'=>$error];
                }
            }

            if(empty($processed))
            {
                return $result;
            }

            $ack = array_map(static function(array $item): array
            {
                return [
                    'rec_id' => $item['rec_id'],
                    'status' => $item['success'] ? 0 : 1,
                    'message' => $item['success'] ? 'OK' : self::truncate($item['error'], 240),
                ];
            }, $processed);

            try {
                $ackResponse = $this->client()->request('logistics_sync_ack.php', [
                    'logistics_list' => json_encode($ack, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
                $ackErrors = $this->ackErrors($ackResponse, array_column($processed, 'rec_id'));
                foreach($processed as $item)
                {
                    $ackError = $ackErrors[$item['rec_id']] ?? $ackErrors['*'] ?? '';
                    if($ackError !== '')
                    {
                        $this->store()->markFailure(
                            'logistics',
                            $item['rec_id'],
                            $ackError,
                            $now,
                            $this->responseSummary($ackResponse)
                        );
                        $result['failed']++;
                    } elseif($item['success']) {
                        $this->store()->markSuccess('logistics', $item['rec_id'], $this->responseSummary($ackResponse), $now);
                        $result['success']++;
                    } else {
                        $this->store()->markFailure(
                            'logistics',
                            $item['rec_id'],
                            $item['error'],
                            $now,
                            $this->responseSummary($ackResponse)
                        );
                        $result['failed']++;
                    }
                }
            } catch(\Throwable $e) {
                foreach($processed as $item)
                {
                    $this->store()->markFailure(
                        'logistics',
                        $item['rec_id'],
                        $this->safeError($e),
                        $now,
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
