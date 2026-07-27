<?php
namespace app\integration\wangdian;

trait WangDianAftersaleSyncWorkflow
{
    public function pushAftersales(): array
    {
        $result = $this->result();
        if(!$this->isEnabled())
        {
            return $result;
        }

        try {
            $this->assertShopNumber();
            $now = $this->now();
            $items = $this->store()->pendingAftersales(
                min(10, max(1, (int)($this->config['aftersale_batch_size'] ?? 10))),
                $now
            );
            $refunds = [];
            $attempts = [];
            foreach($items as $item)
            {
                $aftersale = $item['aftersale'] ?? [];
                $aftersaleId = (int)($aftersale['id'] ?? 0);
                $order = $item['order'] ?? [];
                $businessKey = trim((string)($item['key'] ?? ''));
                if($aftersaleId <= 0 || $businessKey === '' || !$this->store()->startAttempt('aftersale_push', $businessKey, (int)($order['id'] ?? 0), [
                    'aftersale_id'=>$aftersaleId,
                    'order_no'=>(string)($order['order_no'] ?? ''),
                    'type'=>(int)($aftersale['type'] ?? -1),
                    'status'=>(int)($aftersale['status'] ?? -1),
                ], $now))
                {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $refund = WangDianAftersaleMapper::map(
                        $aftersale,
                        $order,
                        $item['detail'] ?? [],
                        (string)$this->config['shop_no']
                    );
                    $refunds[] = $refund;
                    $attempts[] = ['key'=>$businessKey, 'refund_no'=>$refund['refund_no']];
                } catch(\Throwable $e) {
                    $this->store()->markFailure('aftersale_push', $businessKey, $this->safeError($e), $now);
                    $result['failed']++;
                }
            }
            if(empty($refunds))
            {
                return $result;
            }

            $this->pushBatchRecords(
                WangDianBatchPushDefinition::aftersales(),
                $refunds,
                $attempts,
                $now,
                $result
            );
        } catch(\Throwable $e) {
            $result['failed']++;
            $result['message'] = $this->safeError($e);
        }
        return $result;
    }

    public function syncAftersales(): array
    {
        $result = $this->result();
        if(!$this->isEnabled())
        {
            return $result;
        }

        try {
            $this->assertShopNumber();
            $now = $this->now();
            $lookback = min(2592000, max(300, (int)($this->config['aftersale_query_lookback'] ?? 604800)));
            $pageSize = 40;
            $autoProcess = filter_var($this->config['aftersale_auto_process'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $startTime = $this->formatTimestamp($now-$lookback);
            $endTime = $this->formatTimestamp($now);
            $total = null;
            $received = 0;
            $seenPages = [];
            $seenRefunds = [];
            for($page = 0; ; $page++)
            {
                $response = $this->client()->request('refund_query.php', [
                    'start_time'=>$startTime,
                    'end_time'=>$endTime,
                    'page_size'=>(string)$pageSize,
                    'page_no'=>(string)$page,
                    'shop_no'=>(string)$this->config['shop_no'],
                ]);
                $records = $response['refunds'] ?? [];
                if(!is_array($records))
                {
                    throw new \RuntimeException('Wangdiantong refund response did not contain a valid refunds list.');
                }
                if(!array_key_exists('total_count', $response))
                {
                    throw new \RuntimeException('Wangdiantong refund response did not contain total_count.');
                }
                $responseTotal = self::parseNonNegativeInteger($response['total_count']);
                if($responseTotal === null)
                {
                    throw new \RuntimeException('Wangdiantong refund response contained an invalid total_count.');
                }
                if($total !== null && $total !== $responseTotal)
                {
                    throw new \RuntimeException('Wangdiantong refund total_count changed during pagination.');
                }
                $total = $responseTotal;
                $pageSignature = hash('sha256', json_encode(
                    $records,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                ));
                if(isset($seenPages[$pageSignature]))
                {
                    throw new \RuntimeException('Wangdiantong refund pagination returned a repeated page.');
                }
                $seenPages[$pageSignature] = true;
                $pageRefunds = [];
                foreach($records as $record)
                {
                    if(!is_array($record))
                    {
                        throw new \RuntimeException('Wangdiantong refund pagination contained a malformed record.');
                    }
                    $refundId = trim((string)($record['refund_id'] ?? ''));
                    $sourceNo = trim((string)($record['src_no'] ?? ''));
                    if($sourceNo === '')
                    {
                        $sourceNo = trim((string)($record['api_outer_no'] ?? ''));
                    }
                    if($refundId === '' && $sourceNo === '')
                    {
                        throw new \RuntimeException('Wangdiantong refund pagination contained a record without a stable identifier.');
                    }
                    $refundIdentity = $refundId !== '' ? 'refund_id:'.$refundId : 'source_no:'.$sourceNo;
                    if(isset($seenRefunds[$refundIdentity]) || isset($pageRefunds[$refundIdentity]))
                    {
                        throw new \RuntimeException('Wangdiantong refund pagination returned an overlapping record.');
                    }
                    $pageRefunds[$refundIdentity] = true;
                }
                $seenRefunds += $pageRefunds;
                $pageCount = count($pageRefunds);
                if($pageCount > $total-$received)
                {
                    throw new \RuntimeException('Wangdiantong refund pagination returned more unique records than total_count.');
                }
                $nextReceived = $received+$pageCount;
                if($pageCount < $pageSize && $nextReceived < $total)
                {
                    throw new \RuntimeException('Wangdiantong refund pagination ended before total_count was reached.');
                }
                foreach($records as $record)
                {
                    $sourceNo = trim((string)($record['src_no'] ?? ''));
                    if($sourceNo === '')
                    {
                        $sourceNo = trim((string)($record['api_outer_no'] ?? ''));
                    }
                    if(preg_match('/^SHOPXO-AS-\d+$/', $sourceNo) !== 1)
                    {
                        $result['skipped']++;
                        continue;
                    }
                    $modified = trim((string)($record['modified'] ?? ''));
                    $processStatus = (int)($record['process_status'] ?? 0);
                    $businessKey = $sourceNo.':'.$processStatus.':'.$modified.':'.($autoProcess ? 'apply' : 'observe');
                    $attemptTime = $this->now();
                    if(!$this->store()->startAttempt('aftersale_status', $businessKey, 0, [
                        'aftersale_no'=>$sourceNo,
                        'erp_refund_id'=>(string)($record['refund_id'] ?? ''),
                        'process_status'=>$processStatus,
                        'modified'=>$modified,
                    ], $attemptTime))
                    {
                        $result['skipped']++;
                        continue;
                    }

                    try {
                        $this->store()->applyAftersaleStatus(
                            $record,
                            $autoProcess,
                            min(2, max(0, (int)($this->config['aftersale_refundment'] ?? 0)))
                        );
                        $this->store()->markSuccess('aftersale_status', $businessKey, $this->responseSummary($response), $attemptTime);
                        $result['success']++;
                    } catch(\Throwable $e) {
                        $this->store()->markFailure('aftersale_status', $businessKey, $this->safeError($e), $attemptTime);
                        $result['failed']++;
                    }
                }

                $received = $nextReceived;
                if($received >= $total)
                {
                    break;
                }
            }
        } catch(\Throwable $e) {
            $result['failed']++;
            $result['message'] = $this->safeError($e);
        }
        return $result;
    }

}
