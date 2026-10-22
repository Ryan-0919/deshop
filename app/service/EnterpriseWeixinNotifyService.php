<?php

namespace app\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 企业微信群机器人订单通知。
 *
 * 只发送支付成功订单，不记录机器人地址、响应体或其它凭据。
 */
class EnterpriseWeixinNotifyService
{
    private const CACHE_PREFIX = 'enterprise_wechat_order_paid_';
    private const CACHE_TTL = 2592000;

    /**
     * 支付成功钩子处理。
     * 该方法必须吞掉所有外部通知异常，不能影响原支付事务。
     */
    public static function OrderPaySuccess(array $params = []): void
    {
        try {
            $config = self::Settings();
            if (empty($config['enabled'])) {
                return;
            }

            $webhookUrl = (string) ($config['webhook_url'] ?? '');
            if (!self::IsValidWebhookUrl($webhookUrl)) {
                Log::warning('Enterprise WeChat order notification is enabled but webhook configuration is invalid');
                return;
            }

            $orderId = isset($params['order_id']) ? (int) $params['order_id'] : 0;
            if ($orderId <= 0 && !empty($params['order']['id'])) {
                $orderId = (int) $params['order']['id'];
            }
            if ($orderId <= 0) {
                return;
            }

            $cacheKey = self::CACHE_PREFIX . $orderId;
            if ((int) MyCache($cacheKey) === 1) {
                return;
            }

            // 在请求关闭阶段读取，确保只通知已经提交并标记为支付成功的订单。
            $order = Db::name('Order')->where(['id' => $orderId])
                ->field('id,order_no,user_id,total_price,pay_price,pay_time,pay_status')
                ->find();
            if (empty($order) || (int) $order['pay_status'] !== 1) {
                return;
            }

            $user = Db::name('User')->where(['id' => (int) $order['user_id']])
                ->field('id,username,nickname,referrer')
                ->find() ?: [];
            $referrer = [];
            if (!empty($user['referrer'])) {
                $referrer = Db::name('User')->where(['id' => (int) $user['referrer']])
                    ->field('id,username,nickname,enterprise_wechat_userid')
                    ->find() ?: [];
            }

            $details = Db::name('OrderDetail')->where(['order_id' => $orderId])
                ->field('title,buy_number')
                ->order('id asc')
                ->limit(9)
                ->select()
                ->toArray();

            $content = self::BuildMessage($order, $user, $referrer, $details);
            $payload = [
                'msgtype' => 'text',
                'text' => [
                    'content' => $content,
                ],
            ];
            $mentionMobiles = $config['mention_mobiles'] ?? [];
            if (!empty($mentionMobiles)) {
                $payload['text']['mentioned_mobile_list'] = array_values($mentionMobiles);
            }
            $mentionUserIds = self::MentionedUserIds($referrer);
            if (!empty($mentionUserIds)) {
                $payload['text']['mentioned_list'] = $mentionUserIds;
            }

            if (!self::Send($webhookUrl, $payload, (int) ($config['timeout'] ?? 3))) {
                return;
            }

            // 只有企业微信确认接受后才标记，重复支付回调不会重复刷屏。
            MyCache($cacheKey, 1, self::CACHE_TTL);
        } catch (\Throwable $e) {
            Log::warning('Enterprise WeChat order notification failed', [
                'order_id' => isset($params['order_id']) ? (int) $params['order_id'] : 0,
            ]);
        }
    }

    /**
     * 校验企业微信机器人地址，只允许官方 HTTPS 主机，防止 SSRF。
     */
    public static function IsValidWebhookUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'qyapi.weixin.qq.com'
            && !empty($parts['query']);
    }

    /** 返回可用于企业微信机器人的成员 userid；无有效映射时不艾特。 */
    public static function MentionedUserIds(array $referrer): array
    {
        $userId = trim((string) ($referrer['enterprise_wechat_userid'] ?? ''));
        return ($userId !== '' && preg_match('/^[A-Za-z0-9_@.-]{1,64}$/', $userId)) ? [$userId] : [];
    }

    /**
     * 后台配置优先；未安装配置行时回退到环境变量，兼容已有部署。
     */
    private static function Settings(): array
    {
        $config = MyConfig('enterprise_wechat');
        $enabled = MyC('enterprise_wechat_order_enabled', null);
        $webhook = MyC('enterprise_wechat_order_webhook_url', null);
        $timeout = MyC('enterprise_wechat_order_timeout', null);
        $mentions = MyC('enterprise_wechat_order_mention_mobiles', null);

        if ($enabled !== null) {
            $config['enabled'] = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }
        if ($webhook !== null) {
            $config['webhook_url'] = htmlspecialchars_decode((string) $webhook, ENT_QUOTES);
        }
        if ($timeout !== null) {
            $config['timeout'] = min(5, max(1, (int) $timeout));
        }
        if ($mentions !== null) {
            $values = preg_split('/[\s,]+/', trim((string) $mentions)) ?: [];
            $config['mention_mobiles'] = array_values(array_filter(array_map('trim', $values), static fn(string $mobile): bool => $mobile !== ''));
        }
        return $config;
    }

    private static function BuildMessage(array $order, array $user, array $referrer, array $details): string
    {
        $items = [];
        foreach (array_slice($details, 0, 8) as $detail) {
            $title = self::CleanText((string) ($detail['title'] ?? '商品'));
            $number = max(1, (int) ($detail['buy_number'] ?? 1));
            $items[] = $title . ' x' . $number;
        }
        if (count($details) > 8) {
            $items[] = '等共 ' . count($details) . ' 项';
        }
        $itemText = empty($items) ? '无商品明细' : implode('；', $items);
        return '学员已下单：' . $itemText . '，请尽快联系';
    }

    private static function UserName(array $user, int $fallbackId): string
    {
        $name = trim((string) ($user['nickname'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($user['username'] ?? ''));
        }
        return self::CleanText($name !== '' ? $name : ('用户#' . $fallbackId));
    }

    private static function CleanText(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private static function Send(string $url, array $payload, int $timeout): bool
    {
        if (!function_exists('curl_init')) {
            Log::warning('Enterprise WeChat order notification skipped because cURL is unavailable');
            return false;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return false;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min(5, $timeout)),
            CURLOPT_TIMEOUT => max(1, min(5, $timeout)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($response === false || $curlError !== '' || $httpCode !== 200) {
            Log::warning('Enterprise WeChat order notification request failed', [
                'http_code' => $httpCode,
            ]);
            return false;
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || (int) ($decoded['errcode'] ?? -1) !== 0) {
            Log::warning('Enterprise WeChat order notification was rejected', [
                'http_code' => $httpCode,
            ]);
            return false;
        }
        return true;
    }
}
