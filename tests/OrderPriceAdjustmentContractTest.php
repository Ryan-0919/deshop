<?php

require dirname(__DIR__).'/vendor/autoload.php';

function assertOrderPriceAdjustmentContract(bool $condition, string $message): void
{
    if(!$condition)
    {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents(dirname(__DIR__).'/app/service/OrderService.php');
$controller = file_get_contents(dirname(__DIR__).'/app/admin/controller/Order.php');
$operate = file_get_contents(dirname(__DIR__).'/app/admin/view/default/order/module/operate.html');
$form = file_get_contents(dirname(__DIR__).'/app/admin/view/default/order/index.html');
$migration = file_get_contents(dirname(__DIR__).'/scripts/sql/20260907_order_price_adjustment.sql');

assertOrderPriceAdjustmentContract(str_contains($service, 'public static function OrderPriceAdjustment'), 'Order service must expose the price adjustment operation');
assertOrderPriceAdjustmentContract(str_contains($service, "'pay_status' !== 0") || str_contains($service, '(int) $order[\'pay_status\'] !== 0'), 'Paid orders must be rejected');
assertOrderPriceAdjustmentContract(str_contains($service, '(int) $order[\'status\'] !== 1'), 'Only confirmed pending-payment orders may be adjusted');
assertOrderPriceAdjustmentContract(str_contains($service, "->where('status', 0)"), 'Unpaid payment logs must be closed after adjustment');
assertOrderPriceAdjustmentContract(str_contains($service, "->where(['id'=>\$order_ids])->lock(true)"), 'Payment creation must lock and re-read orders before creating a payment log');
assertOrderPriceAdjustmentContract(str_contains($service, '支付金额与当前订单金额不一致，请重新发起支付'), 'Payment callbacks must reject stale amounts after a price adjustment');
assertOrderPriceAdjustmentContract(str_contains($service, "->where(['id'=>\$params['pay_log_data']['id']])->lock(true)"), 'Payment callbacks must lock the payment log before settlement');
assertOrderPriceAdjustmentContract(str_contains($service, 'OrderPriceAdjustment'), 'The operation must persist an audit record');
assertOrderPriceAdjustmentContract(str_contains($controller, 'public function ModifyPrice'), 'Admin order controller must expose the operation');
assertOrderPriceAdjustmentContract(str_contains($operate, "data-am-modal=\"{target: '#order-modify-price-modal'}\""), 'Pending orders need an admin price adjustment button');
assertOrderPriceAdjustmentContract(str_contains($form, "action=\"{{:MyUrl('admin/order/modifyprice')}}\""), 'Admin UI must submit the price adjustment form');
assertOrderPriceAdjustmentContract(str_contains($migration, 'sxo_order_price_adjustment'), 'Migration must create the adjustment audit table');
assertOrderPriceAdjustmentContract(str_contains($migration, "'Order', 'ModifyPrice'"), 'Migration must add the dedicated admin permission');

echo "Order price adjustment contract tests passed\n";
