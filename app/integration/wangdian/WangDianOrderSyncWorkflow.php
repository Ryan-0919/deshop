<?php
namespace app\integration\wangdian;

trait WangDianOrderSyncWorkflow
{
    public function pushOrders(): array
    {
        $result = $this->result();
        if(!$this->isEnabled())
        {
            return $result;
        }

        $syncFrom = (int)($this->config['order_sync_from'] ?? 0);
        if($syncFrom <= 0)
        {
            return $this->configurationFailure($result, 'WANGDIAN_ORDER_SYNC_FROM is required before order synchronization can run.');
        }

        try {
            $this->assertShopNumber();
            $now = $this->now();
            $orders = $this->store()->pendingOrders(
                $syncFrom,
                min(50, max(1, (int)($this->config['order_batch_size'] ?? 50))),
                $now
            );
            $trades = [];
            $attempts = [];
            foreach($orders as $item)
            {
                $order = $item['order'] ?? [];
                $orderId = (int)($order['id'] ?? 0);
                $businessKey = trim((string)($item['key'] ?? ''));
                if($businessKey === '')
                {
                    $businessKey = (string)$orderId;
                }
                if($orderId <= 0 || !$this->store()->startAttempt('order_push', $businessKey, $orderId, [
                    'order_id' => $orderId,
                    'order_no' => (string)($order['order_no'] ?? ''),
                    'sku_count' => count($item['details'] ?? []),
                ], $now))
                {
                    $result['skipped']++;
                    continue;
                }

                try {
                    if(!empty($item['validation_error']))
                    {
                        throw new \InvalidArgumentException((string)$item['validation_error']);
                    }
                    $trades[] = WangDianOrderMapper::map($order, $item['address'] ?? [], $item['details'] ?? []);
                    $attempts[] = [
                        'key'=>$businessKey,
                        'order_id'=>$orderId,
                        'order_no'=>(string)($order['order_no'] ?? ''),
                    ];
                } catch(\Throwable $e) {
                    $this->store()->markFailure('order_push', $businessKey, $this->safeError($e), $now);
                    $result['failed']++;
                }
            }

            if(empty($trades))
            {
                return $result;
            }

            $this->pushBatchRecords(
                WangDianBatchPushDefinition::orders((string)$this->config['shop_no']),
                $trades,
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

}
