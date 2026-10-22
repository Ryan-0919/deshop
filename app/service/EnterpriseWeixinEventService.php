<?php

namespace app\service;

use app\listener\EnterpriseWeixinOrderListener;
use think\Service;

/**
 * 注册企业微信订单通知监听器。
 *
 * 通过应用服务注册，避免后台“钩子部署”重写 app/event.php 时丢失监听器。
 */
class EnterpriseWeixinEventService extends Service
{
    public function boot(): void
    {
        $this->app->event->listen(
            'plugins_service_order_pay_success_handle_end',
            EnterpriseWeixinOrderListener::class
        );
    }
}

