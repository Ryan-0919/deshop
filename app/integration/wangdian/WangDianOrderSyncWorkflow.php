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

            try {
                $response = $this->client()->request('trade_push.php', [
                    'shop_no' => (string)$this->config['shop_no'],
                    'switch' => '0',
                    'trade_list' => json_encode($trades, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
                $tradeErrors = $this->tradeErrors($response);
                if(!$this->tradeCountMatches($response, $attempts, $tradeErrors))
                {
                    foreach($attempts as $index=>$attempt)
                    {
                        $this->retryOrderIndividually($trades[$index], $attempt, $now, $result);
                    }
                    return $result;
                }
                foreach($attempts as $attempt)
                {
                    $error = $tradeErrors[$attempt['order_no']] ?? $tradeErrors['*'] ?? '';
                    if($error === '')
                    {
                        $this->store()->markSuccess('order_push', $attempt['key'], $this->responseSummary($response), $now);
                        $result['success']++;
                    } else {
                        $this->store()->markFailure(
                            'order_push',
                            $attempt['key'],
                            $error,
                            $now,
                            $this->responseSummary($response)
                        );
                        $result['failed']++;
                    }
                }
            } catch(\Throwable $e) {
                foreach($attempts as $attempt)
                {
                    $this->store()->markFailure(
                        'order_push',
                        $attempt['key'],
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

    private function tradeErrors(array $response): array
    {
        $errors = $response['errors'] ?? [];
        if(empty($errors) || !is_array($errors))
        {
            return [];
        }

        $result = [];
        foreach($errors as $key=>$error)
        {
            if(is_array($error))
            {
                $orderNo = trim((string)($error['tid'] ?? $error['order_no'] ?? ''));
                $message = trim((string)($error['error'] ?? $error['message'] ?? 'Order synchronization failed.'));
            } else {
                $orderNo = is_string($key) ? trim($key) : '';
                $message = trim((string)$error);
            }
            $result[$orderNo === '' ? '*' : $orderNo] = self::truncate(
                WangDianLogSanitizer::sanitizeText($message === '' ? 'Order synchronization failed.' : $message),
                1000
            );
        }
        return $result;
    }

    private function tradeCountMatches(array $response, array $attempts, array $errors): bool
    {
        if(!array_key_exists('new_count', $response) && !array_key_exists('chg_count', $response))
        {
            return false;
        }
        $accepted = self::acceptedCount($response);
        if($accepted === null)
        {
            return false;
        }
        $expectedAccepted = count(array_filter($attempts, static function(array $attempt) use ($errors): bool
        {
            return !isset($errors[$attempt['order_no']]) && !isset($errors['*']);
        }));
        return $accepted === $expectedAccepted;
    }

    private function retryOrderIndividually(array $trade, array $attempt, int $now, array &$result): void
    {
        try {
            $response = $this->client()->request('trade_push.php', [
                'shop_no'=>(string)$this->config['shop_no'],
                'switch'=>'0',
                'trade_list'=>json_encode([$trade], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $errors = $this->tradeErrors($response);
            if(!$this->tradeCountMatches($response, [$attempt], $errors))
            {
                throw new WangDianApiException('Wangdiantong did not accept order '.$attempt['order_no'].'.', $response);
            }
            $error = $errors[$attempt['order_no']] ?? $errors['*'] ?? '';
            if($error !== '')
            {
                $this->store()->markFailure('order_push', $attempt['key'], $error, $now, $this->responseSummary($response));
                $result['failed']++;
                return;
            }
            $this->store()->markSuccess('order_push', $attempt['key'], $this->responseSummary($response), $now);
            $result['success']++;
        } catch(\Throwable $e) {
            $this->store()->markFailure(
                'order_push',
                $attempt['key'],
                $this->safeError($e),
                $now,
                $this->exceptionResponseSummary($e)
            );
            $result['failed']++;
        }
    }

}
