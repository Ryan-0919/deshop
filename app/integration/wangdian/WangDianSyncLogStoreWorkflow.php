<?php
namespace app\integration\wangdian;

use think\facade\Db;

trait WangDianSyncLogStoreWorkflow
{
    public function startAttempt(string $businessType, string $businessKey, int $orderId, array $requestSummary, int $now): bool
    {
        Db::name('WangdianSyncLog')->extra('IGNORE')->insert([
            'business_type'=>$businessType,
            'business_key'=>$businessKey,
            'order_id'=>$orderId,
            'status'=>2,
            'attempts'=>0,
            'request_payload'=>$this->encodeSummary($requestSummary),
            'last_error'=>'',
            'next_retry_time'=>0,
            'last_attempt_time'=>0,
            'synced_time'=>0,
            'add_time'=>$now,
            'upd_time'=>$now,
        ]);
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
                throw new \RuntimeException('Failed to claim the Wangdiantong synchronization log row.');
            }
            $data['attempts'] = (int)$log['attempts']+1;
            Db::name('WangdianSyncLog')->where(['id'=>$log['id']])->update($data);
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
            'status'=>0,
            'last_attempt_time'=>$now,
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
        $where = [
            'business_type'=>$businessType,
            'business_key'=>$businessKey,
            'status'=>0,
            'last_attempt_time'=>$now,
        ];
        $attempts = (int)Db::name('WangdianSyncLog')->where($where)->value('attempts');
        if($attempts <= 0)
        {
            return;
        }
        $retrySeconds = min(self::MAX_RETRY_SECONDS, 60 * (2 ** min(6, $attempts-1)));
        $data = [
            'status'=>2,
            'last_error'=>$this->truncate(WangDianLogSanitizer::sanitizeText($error), 1000),
            'next_retry_time'=>$now+$retrySeconds,
            'upd_time'=>$now,
        ];
        if(!empty($responseSummary))
        {
            $data['response_payload'] = $this->encodeSummary($responseSummary);
        }
        Db::name('WangdianSyncLog')->where($where)->update($data);
    }

    private function encodeSummary(array $summary): string
    {
        $encoded = json_encode(WangDianLogSanitizer::sanitize($summary), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->truncate($encoded === false ? '{}' : $encoded, 32000);
    }

}
