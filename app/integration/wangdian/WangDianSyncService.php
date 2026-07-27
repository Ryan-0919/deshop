<?php
namespace app\integration\wangdian;

final class WangDianSyncService
{
    use WangDianBatchPushWorkflow;
    use WangDianOrderSyncWorkflow;
    use WangDianLogisticsSyncWorkflow;
    use WangDianInventorySyncWorkflow;
    use WangDianAftersaleSyncWorkflow;

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

    private function formatTimestamp(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
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
        foreach(['new_count', 'chg_count', 'total_count'] as $countField)
        {
            if(array_key_exists($countField, $response))
            {
                $count = self::parseNonNegativeInteger($response[$countField]);
                if($count === null)
                {
                    $summary[$countField.'_invalid'] = true;
                } else {
                    $summary[$countField] = $count;
                }
            }
        }
        return WangDianLogSanitizer::sanitize($summary);
    }

    private function ackErrors(array $response, array $attemptedRecIds): array
    {
        $result = [];
        $attempted = array_fill_keys(array_map('strval', $attemptedRecIds), true);
        foreach((array)($response['errors'] ?? []) as $error)
        {
            if(!is_array($error))
            {
                return ['*'=>'Wangdiantong acknowledgement contained a malformed error record.'];
            }
            $recId = trim((string)($error['rec_id'] ?? ''));
            if($recId === '' || !isset($attempted[$recId]))
            {
                return ['*'=>'Wangdiantong acknowledgement referenced an unexpected record.'];
            }
            $result[$recId] = self::truncate(WangDianLogSanitizer::sanitizeText(trim((string)($error['error'] ?? $error['message'] ?? 'Acknowledgement failed.'))), 1000);
        }
        return $result;
    }

    private static function parseNonNegativeInteger($value): ?int
    {
        if(is_int($value))
        {
            return $value >= 0 ? $value : null;
        }
        if(!is_string($value) || preg_match('/^(?:0|[1-9]\d*)$/D', $value) !== 1)
        {
            return null;
        }
        $max = (string)PHP_INT_MAX;
        if(strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0))
        {
            return null;
        }
        return (int)$value;
    }

    private function safeError(\Throwable $error): string
    {
        $message = WangDianLogSanitizer::sanitizeText($error->getMessage());
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
