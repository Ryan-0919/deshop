<?php

// Standalone configuration regression test; does not connect to a database.
namespace app\service {
    class PaymentService
    {
        public static function PaymentList($params = [])
        {
            return [];
        }
    }

    class FormTableService
    {
        public static array $fields = [];

        public static function FieldsSelectData($params = [])
        {
            return ['data' => self::$fields];
        }
    }
}

namespace {
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    define('__MY_DOMAIN__', 'https://example.invalid/');
    define('__MY_PUBLIC_URL__', 'https://example.invalid/');
    function MyConfig($key)
    {
        return $key === 'database.connections.mysql.prefix' ? 'test_' : null;
    }

    $language = require dirname(__DIR__).'/app/admin/lang/zh.php';
    $mobile = false;

    function MyLang($key)
    {
        global $language;
        $value = $language;
        foreach(explode('.', $key) as $part)
        {
            if(!is_array($value) || !array_key_exists($part, $value))
            {
                return $key;
            }
            $value = $value[$part];
        }
        return $value;
    }

    function MyConst($key)
    {
        return [];
    }

    function IsMobile()
    {
        global $mobile;
        return $mobile;
    }

    require dirname(__DIR__).'/app/admin/form/Order.php';
    require dirname(__DIR__).'/app/module/FormTableHandleModule.php';

    class OrderListColumnsFixture extends \app\admin\form\Order
    {
        public function OrderWarehouseList()
        {
            return [];
        }
    }

    function checkOrderColumns($condition, $message)
    {
        if(!$condition)
        {
            throw new RuntimeException($message);
        }
    }

    function orderColumnsTable($config, $export = false)
    {
        $table = new \app\module\FormTableHandleModule();
        $table->form_data = $config;
        $table->md5_key = 'order-list-columns-test';
        $table->is_export_excel = $export;
        $table->FormFieldsUserSelect();
        return $table;
    }

    $priority = ['order/module/goods', 'order_no', 'add_time', 'status_name', 'pay_status_name', 'pay_price', 'order/module/express'];
    foreach([false, true] as $mobile)
    {
        $order = new OrderListColumnsFixture();
        $config = $order->Run();
        $keys = array_column($config['form'], 'view_key');
        checkOrderColumns(count($keys) === count(array_unique($keys)), 'A moved column must not appear twice');
        checkOrderColumns(array_slice($keys, 0, count($priority)) === $priority, 'Basic information must lead, followed by transaction and logistics columns');

        $fields = array_column($config['form'], null, 'view_key');
        checkOrderColumns($fields['order/module/goods']['search_config']['where_value_custom'] === 'WhereBaseGoodsInfo', 'Basic information must retain the order/product search handler');
        checkOrderColumns($fields['order_no']['is_copy'] === 1 && $fields['order_no']['is_export'] === 1, 'Order number must remain copyable and exportable');
        checkOrderColumns($fields['order_no']['is_detail'] === 0, 'Do not duplicate the detail page order number');
        checkOrderColumns($fields['add_time']['search_config']['form_type'] === 'datetime', 'Date range filtering must remain available');
        checkOrderColumns($fields['status_name']['search_config']['form_name'] === 'status', 'Order status filter must keep its key');
        checkOrderColumns($fields['pay_status_name']['search_config']['form_name'] === 'pay_status', 'Payment status filter must keep its key');
        checkOrderColumns($fields['pay_price']['search_config'] === ['form_type'=>'section', 'is_point'=>1], 'Paid amount must retain decimal range filtering');
        checkOrderColumns($fields['order/module/express']['search_config']['where_value_custom'] === 'WhereExpressInfo', 'Logistics lookup must keep the existing handler');
        foreach(['add_time', 'status_name', 'pay_status_name', 'pay_price'] as $key)
        {
            checkOrderColumns($fields[$key]['is_sort'] === 1, 'Sorting must remain available for '.$key);
        }
        foreach(['user_export', 'user_referrer_export', 'user_mobile', 'address_contact_tel'] as $key)
        {
            checkOrderColumns(!empty($fields[$key]['is_export']), 'Buyer, distributor and telephone export fields must remain present: '.$key);
        }
        checkOrderColumns($config['data']['handle_field_level_merge_data']['user_mobile'] === 'user.mobile', 'Buyer phone must come from the user account');
        checkOrderColumns($config['data']['handle_field_level_merge_data']['address_contact_tel'] === 'address_data.tel', 'Delivery phone must come from the order address');
        foreach(['user_mobile', 'address_contact_tel'] as $key)
        {
            checkOrderColumns($fields[$key]['is_list'] === 0 && $fields[$key]['is_detail'] === 0, 'Export fix must not add duplicate telephone fields to the UI');
        }
        checkOrderColumns(end($keys) === 'order/module/operate', 'Actions must stay last');
        checkOrderColumns($fields['order/module/operate']['fixed'] === 'right', 'Actions must remain right-fixed');

        \app\service\FormTableService::$fields = [];
        $table = orderColumnsTable($config);
        $visible = array_filter($table->form_data['form'], function($field) {
            return !isset($field['is_list']) || $field['is_list'] == 1;
        });
        checkOrderColumns(array_slice(array_column($visible, 'view_key'), 0, count($priority)) === $priority, 'Basic information must display first, followed by the six transaction and logistics columns');

        // Existing per-admin preferences are intentionally preserved, not reset.
        \app\service\FormTableService::$fields = [
            ['key'=>'lib-module-user', 'checked'=>1],
            ['key'=>'pay_price', 'checked'=>1],
            ['key'=>'status_name', 'checked'=>0],
        ];
        $custom = orderColumnsTable($config);
        checkOrderColumns(array_column($custom->form_data['form'], 'view_key') === ['lib/module/user', 'pay_price', 'status_name', 'order/module/operate'], 'Personal column ordering must remain intact');
        checkOrderColumns($custom->form_data['form'][2]['is_list'] === 0, 'Personal hidden columns must stay hidden');

        $export = orderColumnsTable($config, true);
        $exportKeys = array_column($export->form_data['form'], 'view_key');
        foreach(['order_no', 'user_export', 'user_referrer_export', 'user_mobile', 'address_contact_tel'] as $key)
        {
            checkOrderColumns(in_array($key, $exportKeys, true), 'Required export column must survive historical preferences: '.$key);
        }
        foreach([[], [
            ['key'=>'user_mobile', 'checked'=>0],
            ['key'=>'address_contact_tel', 'checked'=>0],
            ['key'=>'lib-module-user', 'checked'=>1],
        ]] as $preferences)
        {
            \app\service\FormTableService::$fields = $preferences;
            $phoneExport = orderColumnsTable($config, true);
            $counts = array_count_values(array_column($phoneExport->form_data['form'], 'view_key'));
            foreach(['user_mobile', 'address_contact_tel'] as $key)
            {
                checkOrderColumns(($counts[$key] ?? 0) === 1, 'Telephone export column must appear exactly once with default or hidden preferences: '.$key);
            }
            checkOrderColumns(\app\service\FormTableService::$fields === $preferences, 'Export must not change personal column preferences');
        }
        checkOrderColumns(!in_array(['pay_status', '=', 1], $order->condition_base, true), 'Normal list must not be restricted to paid orders');
        $exportOrder = new OrderListColumnsFixture(['form_table_is_export_excel'=>1]);
        checkOrderColumns(in_array(['pay_status', '=', 1], $exportOrder->condition_base, true), 'Export paid-order restriction must remain intact');
    }

    echo "Order list column tests passed (desktop, mobile, filters, preferences, export).\n";
}
