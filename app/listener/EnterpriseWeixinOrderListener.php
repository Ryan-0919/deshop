<?php

namespace app\listener;

use app\service\EnterpriseWeixinNotifyService;
use think\facade\Log;

/**
 * 订单支付成功通知监听器。
 *
 * 通知失败只能记录日志，不能让支付事务回滚。
 */
class EnterpriseWeixinOrderListener
{
    public function handle($params = []): ?array
    {
        try {
            $params = is_array($params) ? $params : [];
            // 支付钩子位于数据库事务提交前。延迟到请求关闭阶段，避免通知网络异常
            // 或后续业务回滚时发送“幽灵订单”，同时不阻塞支付事务。
            register_shutdown_function(static function () use ($params): void {
                EnterpriseWeixinNotifyService::OrderPaySuccess($params);
            });
        } catch (\Throwable $e) {
            Log::warning('Enterprise WeChat order notification listener failed');
        }

        // 返回 null，避免被 ShopXO 的钩子错误处理逻辑判定为失败。
        return null;
    }
}
