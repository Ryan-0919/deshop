<?php
namespace app\integration\wangdian;

use app\service\OrderService;
use think\facade\Db;

trait WangDianLogisticsStoreWorkflow
{
    public function applyLogistics(array $record, array $expressMap): int
    {
        $orderNo = trim((string)($record['tid'] ?? ''));
        $expressNumber = trim((string)($record['logistics_no'] ?? ''));
        $expressCode = trim((string)($record['logistics_code_erp'] ?? ''));
        if($orderNo === '' || $expressNumber === '' || $expressCode === '')
        {
            throw new \InvalidArgumentException('The logistics record is missing tid, logistics_no or logistics_code_erp.');
        }

        $normalizedMap = [];
        foreach($expressMap as $code=>$id)
        {
            $normalizedMap[strtoupper(trim((string)$code))] = (int)$id;
        }
        $expressId = $normalizedMap[strtoupper($expressCode)] ?? 0;
        if($expressId <= 0)
        {
            throw new \RuntimeException('No ShopXO express mapping exists for ERP code '.$expressCode.'.');
        }

        $order = Db::name('Order')->where(['order_no'=>$orderNo])->field('id,status,user_id')->find();
        if(empty($order))
        {
            throw new \RuntimeException('ShopXO order '.$orderNo.' was not found.');
        }
        $existing = Db::name('OrderExpress')->where(['order_id'=>$order['id']])->order('id asc')->find();
        if(!empty($existing) && (int)$existing['express_id'] === $expressId && trim((string)$existing['express_number']) === $expressNumber)
        {
            return (int)$order['id'];
        }

        $params = [
            'id'=>(int)$order['id'],
            'user_id'=>(int)$order['user_id'],
            'user_type'=>'admin',
            'express_id'=>$expressId,
            'express_number'=>$expressNumber,
            'creator'=>0,
            'creator_name'=>'Wangdiantong ERP',
        ];
        if(!empty($existing))
        {
            $params['order_express_id'] = (int)$existing['id'];
        }
        $result = OrderService::OrderDelivery($params);
        if((int)($result['code'] ?? -1) !== 0)
        {
            throw new \RuntimeException((string)($result['msg'] ?? 'ShopXO order delivery failed.'));
        }
        return (int)$order['id'];
    }

}
