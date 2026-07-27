<?php
// +----------------------------------------------------------------------
// | ShopXO 国内领先企业级B2C免费开源电商系统
// +----------------------------------------------------------------------
// | Copyright (c) 2011~2099 http://shopxo.net All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( https://opensource.org/licenses/mit-license.php )
// +----------------------------------------------------------------------
// | Author: Devil
// +----------------------------------------------------------------------
namespace app\api\controller;

use app\integration\wangdian\WangDianCronAccessPolicy;
use app\service\CrontabService;

/**
 * 定时任务
 * @author   Devil
 * @blog     http://gong.gg/
 * @version  0.0.1
 * @datetime 2019-08-18T17:19:33+0800
 */
class Crontab extends Common
{
    /**
     * Push paid orders to Wangdiantong ERP.
     */
    public function WangDianOrderPush()
    {
        return $this->WangDianExecute([CrontabService::class, 'WangDianOrderPush']);
    }

    /**
     * Pull shipment details from Wangdiantong ERP.
     */
    public function WangDianLogisticsSync()
    {
        return $this->WangDianExecute([CrontabService::class, 'WangDianLogisticsSync']);
    }

    /**
     * Pull inventory from Wangdiantong ERP.
     */
    public function WangDianInventorySync()
    {
        return $this->WangDianExecute([CrontabService::class, 'WangDianInventorySync']);
    }

    /**
     * Push refund and return changes to Wangdiantong ERP.
     */
    public function WangDianAftersalePush()
    {
        return $this->WangDianExecute([CrontabService::class, 'WangDianAftersalePush']);
    }

    /**
     * Pull refund and return processing states from Wangdiantong ERP.
     */
    public function WangDianAftersaleSync()
    {
        return $this->WangDianExecute([CrontabService::class, 'WangDianAftersaleSync']);
    }

    private function WangDianExecute(callable $sync)
    {
        $config = (array)config('wangdian');
        if(!WangDianCronAccessPolicy::allows(
            trim((string)($_SERVER['REMOTE_ADDR'] ?? '')),
            trim((string)request()->header('x-wangdian-cron-token', '')),
            (string)($config['cron_token'] ?? ''),
            (array)($config['cron_allowed_ips'] ?? [])
        ))
        {
            return response('Forbidden.', 403);
        }
        return $this->WangDianResult(call_user_func($sync));
    }

    private function WangDianResult(array $result): string
    {
        $data = (array)($result['data'] ?? []);
        if((int)($result['code'] ?? -1) !== 0)
        {
            return (string)($result['msg'] ?? 'Wangdiantong synchronization failed.');
        }
        return 'enabled:'.(!empty($data['enabled']) ? '1' : '0')
            .', success:'.(int)($data['success'] ?? 0)
            .', fail:'.(int)($data['failed'] ?? 0)
            .', skipped:'.(int)($data['skipped'] ?? 0)
            .(empty($data['message']) ? '' : ', message:'.$data['message']);
    }

    /**
     * 订单关闭
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  1.0.0
     * @datetime 2019-08-18T17:19:33+0800
     */
    public function OrderClose()
    {
        $ret = CrontabService::OrderClose();
        return 'sucs:'.$ret['data']['sucs'].', fail:'.$ret['data']['fail'];
    }

    /**
     * 订单收货
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  1.0.0
     * @datetime 2019-08-18T17:19:33+0800
     */
    public function OrderSuccess()
    {
        $ret = CrontabService::OrderSuccess();
        return 'sucs:'.$ret['data']['sucs'].', fail:'.$ret['data']['fail'];
    }

    /**
     * 支付日志订单关闭
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  1.0.0
     * @datetime 2019-08-18T17:19:33+0800
     */
    public function PayLogOrderClose()
    {
        $ret = CrontabService::PayLogOrderClose();
        return 'count:'.$ret['data'];
    }

    /**
     * 商品赠送积分
     * @author   Devil
     * @blog     http://gong.gg/
     * @version  1.0.0
     * @datetime 2019-08-18T17:19:33+0800
     */
    public function GoodsGiveIntegral()
    {
        $ret = CrontabService::GoodsGiveIntegral();
        if($ret['code'] == 0)
        {
            return 'sucs:'.$ret['data']['sucs'].', fail:'.$ret['data']['fail'];
        }
        return $ret['msg'];
    }

    /**
     * 微信发货同步
     * @author  Devil
     * @blog    http://gong.gg/
     * @version 1.0.0
     * @datetime 2026-05-26
     */
    public function OrderDeliverySyncWeixin()
    {
        $ret = CrontabService::OrderDeliverySyncWeixin();
        return 'sucs:'.$ret['data']['sucs'].', fail:'.$ret['data']['fail'];
    }
}
?>
