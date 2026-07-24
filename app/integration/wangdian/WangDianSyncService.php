<?php
namespace app\integration\wangdian;

final class WangDianSyncService
{
    private array $config;
    private ?WangDianClientInterface $client;
    private ?WangDianStoreInterface $store;
    private $clock;

    public function __construct(
        array $config,
        ?WangDianClientInterface $client = null,
        ?WangDianStoreInterface $store = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->store = $store;
        $this->clock = $clock ?? static fn(): int => time();
    }

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
                $businessKey = (string)$orderId;
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
                $ackErrors = $this->ackErrors($ackResponse);
                foreach($processed as $item)
                {
                    if(isset($ackErrors[$item['rec_id']]))
                    {
                        $this->store()->markFailure(
                            'logistics',
                            $item['rec_id'],
                            $ackErrors[$item['rec_id']],
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

    public function syncInventory(): array
    {
        $result = $this->result();
        if(!$this->isEnabled())
        {
            return $result;
        }

        try {
            $this->assertShopNumber();
            $now = $this->now();
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
                if(!$this->store()->startAttempt('inventory', $businessKey, 0, [
                    'rec_id'=>$recId,
                    'spec_no'=>(string)($record['spec_no'] ?? ''),
                    'stock_change_count'=>$changeCount,
                ], $now))
                {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $goodsId = $this->store()->applyInventory($record, max(0, (int)($this->config['shopxo_warehouse_id'] ?? 0)));
                    $processed[] = [
                        'key'=>$businessKey,
                        'rec_id'=>$recId,
                        'goods_id'=>$goodsId,
                        'sync_stock'=>(int)$record['sync_stock'],
                        'stock_change_count'=>$changeCount,
                    ];
                } catch(\Throwable $e) {
                    $this->store()->markFailure('inventory', $businessKey, $this->safeError($e), $now);
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
                $ackErrors = $this->ackErrors($ackResponse);
                foreach($processed as $item)
                {
                    if(isset($ackErrors[$item['rec_id']]))
                    {
                        $this->store()->markFailure(
                            'inventory',
                            $item['key'],
                            $ackErrors[$item['rec_id']],
                            $now,
                            $this->responseSummary($ackResponse)
                        );
                        $result['failed']++;
                    } else {
                        $this->store()->markSuccess('inventory', $item['key'], $this->responseSummary($ackResponse), $now);
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

    private function client(): WangDianClientInterface
    {
        return $this->client ??= new WangDianClient($this->config);
    }

    private function store(): WangDianStoreInterface
    {
        return $this->store ??= new WangDianStore();
    }

    private function isEnabled(): bool
    {
        return filter_var($this->config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function assertShopNumber(): void
    {
        if(trim((string)($this->config['shop_no'] ?? '')) === '')
        {
            throw new \RuntimeException('WANGDIAN_SHOP_NO is required.');
        }
    }

    private function now(): int
    {
        return (int)call_user_func($this->clock);
    }

    private function result(): array
    {
        return [
            'enabled'=>$this->isEnabled(),
            'success'=>0,
            'failed'=>0,
            'skipped'=>0,
            'message'=>$this->isEnabled() ? '' : 'Wangdiantong synchronization is disabled.',
        ];
    }

    private function configurationFailure(array $result, string $message): array
    {
        $result['failed'] = 1;
        $result['message'] = $message;
        return $result;
    }

    private function responseSummary(array $response): array
    {
        $summary = [
            'code'=>(int)($response['code'] ?? 0),
            'message'=>self::truncate(trim((string)($response['message'] ?? '')), 500),
        ];
        if(!empty($response['errors']) && is_array($response['errors']))
        {
            $summary['error_count'] = count($response['errors']);
        }
        return $summary;
    }

    private function ackErrors(array $response): array
    {
        $result = [];
        foreach((array)($response['errors'] ?? []) as $error)
        {
            if(!is_array($error))
            {
                continue;
            }
            $recId = trim((string)($error['rec_id'] ?? ''));
            if($recId !== '')
            {
                $result[$recId] = self::truncate(trim((string)($error['error'] ?? $error['message'] ?? 'Acknowledgement failed.')), 1000);
            }
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
                $message === '' ? 'Order synchronization failed.' : $message,
                1000
            );
        }
        return $result;
    }

    private function safeError(\Throwable $error): string
    {
        $message = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error->getMessage()) ?? '');
        return self::truncate($message === '' ? 'Unknown Wangdiantong synchronization error.' : $message, 1000);
    }

    private function exceptionResponseSummary(\Throwable $error): array
    {
        return $error instanceof WangDianApiException
            ? $this->responseSummary($error->response())
            : [];
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
