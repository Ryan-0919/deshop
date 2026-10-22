<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    $root . '/config/enterprise_wechat.php',
    $root . '/app/service.php',
    $root . '/app/service/EnterpriseWeixinEventService.php',
    $root . '/app/listener/EnterpriseWeixinOrderListener.php',
    $root . '/app/service/EnterpriseWeixinNotifyService.php',
    $root . '/app/admin/controller/Config.php',
    $root . '/app/admin/view/default/config/index.html',
    $root . '/scripts/sql/20260907_enterprise_wechat_order_config.sql',
    $root . '/scripts/sql/20260908_enterprise_wechat_userid.sql',
];
foreach ($files as $file) {
    if (!is_file($file)) {
        throw new RuntimeException('Missing file: ' . $file);
    }
}

$config = file_get_contents($files[0]);
$serviceProvider = file_get_contents($files[2]);
$service = file_get_contents($files[4]);
$controller = file_get_contents($files[5]);
$view = file_get_contents($files[6]);
$migration = file_get_contents($files[7]);
$userIdMigration = file_get_contents($files[8]);
foreach ([
    "enterprise_wechat_order_enabled",
    "enterprise_wechat_order_webhook_url",
    "enterprise_wechat_order_timeout",
    "enterprise_wechat_order_mention_mobiles",
] as $key) {
    if (strpos($config, $key) === false) {
        throw new RuntimeException('Missing config key: ' . $key);
    }
}
foreach (['EnterpriseWeixinEventService::class'] as $needle) {
    if (strpos(file_get_contents($files[1]), $needle) === false) {
        throw new RuntimeException('Missing service registration: ' . $needle);
    }
}
foreach (['plugins_service_order_pay_success_handle_end', 'EnterpriseWeixinOrderListener::class'] as $needle) {
    if (strpos($serviceProvider, $needle) === false) {
        throw new RuntimeException('Missing event registration: ' . $needle);
    }
}
if (strpos(file_get_contents($files[3]), 'register_shutdown_function') === false) {
    throw new RuntimeException('Listener must defer notification until after payment transaction');
}
foreach ([
    'qyapi.weixin.qq.com',
    'MyC(\'enterprise_wechat_order_enabled\'',
    'pay_status',
    'mentioned_mobile_list',
    'mentioned_list',
    'enterprise_wechat_userid',
    'CURLOPT_SSL_VERIFYPEER',
] as $needle) {
    if (strpos($service, $needle) === false) {
        throw new RuntimeException('Missing safety/notification behavior: ' . $needle);
    }
}
if (strpos($service, "'学员已下单：' . \$itemText . '，请尽快联系'") === false) {
    throw new RuntimeException('Formal order notification copy is missing');
}
foreach (['enterprise-wechat', 'enterprise_wechat_order_webhook_url', 'enterprise_wechat_order_webhook_clear'] as $needle) {
    if (strpos($controller . $view, $needle) === false) {
        throw new RuntimeException('Missing admin setting UI: ' . $needle);
    }
}
foreach (['enterprise_wechat_order_enabled', 'enterprise_wechat_order_webhook_url', 'enterprise_wechat_order_timeout', 'enterprise_wechat_order_mention_mobiles'] as $needle) {
    if (substr_count($migration, $needle) < 1) {
        throw new RuntimeException('Missing migration config row: ' . $needle);
    }
}
if (strpos($userIdMigration, 'enterprise_wechat_userid') === false) {
    throw new RuntimeException('Missing Enterprise WeChat userid migration');
}

require_once $root . '/app/service/EnterpriseWeixinNotifyService.php';
if (\app\service\EnterpriseWeixinNotifyService::MentionedUserIds(['enterprise_wechat_userid' => 'sales.zhang']) !== ['sales.zhang']) {
    throw new RuntimeException('Valid referrer userid must be mentioned');
}
if (\app\service\EnterpriseWeixinNotifyService::MentionedUserIds(['enterprise_wechat_userid' => 'bad user']) !== []) {
    throw new RuntimeException('Invalid referrer userid must not be mentioned');
}

echo "EnterpriseWeixinNotifyTest: PASS\n";
