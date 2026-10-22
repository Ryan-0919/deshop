<?php

// 企业微信群机器人订单通知配置。
// 默认关闭；启用前请在服务器 .env 中配置机器人地址，不要把地址提交到代码仓库。
$mentionMobiles = preg_split('/[\s,]+/', trim((string) env('enterprise_wechat_order_mention_mobiles', ''))) ?: [];

return [
    'enabled' => filter_var(env('enterprise_wechat_order_enabled', false), FILTER_VALIDATE_BOOLEAN),
    'webhook_url' => trim((string) env('enterprise_wechat_order_webhook_url', '')),
    'timeout' => min(5, max(1, (int) env('enterprise_wechat_order_timeout', 3))),
    'mention_mobiles' => array_values(array_filter(array_map('trim', $mentionMobiles), static fn(string $mobile): bool => $mobile !== '')),
];
