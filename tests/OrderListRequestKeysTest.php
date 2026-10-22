<?php

require __DIR__.'/OrderListColumnsTest.php';

$language['form_table_search_first'] = array_fill_keys(['input', 'select', 'section_min', 'section_max', 'date_start', 'date_end', 'ym'], '');
date_default_timezone_set('Asia/Shanghai');

function DataReturn($message, $code = 0, $data = [])
{
    return ['msg'=>$message, 'code'=>$code, 'data'=>$data];
}

function requestKeyTable($config, $params = [])
{
    $table = new \app\module\FormTableHandleModule();
    $table->form_data = $config;
    $table->out_params = $params;
    $table->where = [];
    $table->where_params = [];
    $table->order_by = ['key'=>$params['fp_order_by_key'] ?? '', 'field'=>'', 'val'=>'desc'];
    $table->module_obj = new OrderListColumnsFixture();
    return $table;
}

class StaleOrderRequestProbe extends \app\module\FormTableHandleModule
{
    public function Init()
    {
        throw new RuntimeException('A stale request must stop before initialization and order queries');
    }
}

$config = (new OrderListColumnsFixture())->Run();
checkOrderColumns(!empty($config['base']['stable_request_keys']), 'Order table must opt into stable request keys');
$table = requestKeyTable($config);
checkOrderColumns($table->FormRequestKeysError() === '', 'Fresh page must be accepted');
$table->FormConfigHandle();
$fields = array_column($table->form_data['form'], null, 'view_key');
$dateKey = $fields['add_time']['form_key'];
$payKey = $fields['pay_status_name']['form_key'];
$priceKey = $fields['pay_price']['form_key'];
$dateSort = $fields['add_time']['sort_key'];
checkOrderColumns(!preg_match('/^f\d+p$/', $dateKey), 'Dates must no longer use a positional key');
checkOrderColumns($fields['order/module/goods']['form_key'] !== $fields['order/module/express']['form_key'], 'Module filters sharing id must have distinct keys');

$params = [
    $dateKey.'_start'=>'2026-09-01 00:00:00',
    $dateKey.'_end'=>'2026-09-28 23:59:59',
    $payKey=>['1'],
    $priceKey.'_min'=>'39.00',
    $priceKey.'_max'=>'1580.00',
    'fp_order_by_key'=>$dateSort,
];
$baseline = requestKeyTable($config, $params);
checkOrderColumns($baseline->FormRequestKeysError() === '', 'Current filters must be accepted');
$baseline->FormConfigHandle();
$baseline->FormOrderByHandle();
checkOrderColumns(in_array(['pay_status', 'in', ['1']], $baseline->where, true), 'Paid filter must remain pay_status');
checkOrderColumns(in_array(['add_time', '>=', strtotime($params[$dateKey.'_start'])], $baseline->where, true), 'Lower date bound must be applied');
checkOrderColumns(in_array(['add_time', '<=', strtotime($params[$dateKey.'_end'])], $baseline->where, true), 'Upper date bound must be applied');
checkOrderColumns(in_array(['pay_price', '>=', '39.00'], $baseline->where, true), 'Lower paid amount bound must be applied');
checkOrderColumns(in_array(['pay_price', '<=', '1580.00'], $baseline->where, true), 'Upper paid amount bound must be applied');
checkOrderColumns($baseline->order_by['data'] === 'add_time desc', 'Sorting must remain bound to add_time');

// Reindex and reverse all columns, as a future layout change or plugin might do.
$reordered = $config;
$reordered['form'] = array_values(array_reverse($config['form']));
$next = requestKeyTable($reordered, $params);
checkOrderColumns($next->FormRequestKeysError() === '', 'Reordering must not invalidate stable filters');
$next->FormConfigHandle();
$next->FormOrderByHandle();
$nextFields = array_column($next->form_data['form'], null, 'view_key');
foreach($fields as $key=>$field)
{
    checkOrderColumns(($field['form_key'] ?? null) === ($nextFields[$key]['form_key'] ?? null), 'Filter key changed for '.$key);
    checkOrderColumns($field['sort_key'] === $nextFields[$key]['sort_key'], 'Sort key changed for '.$key);
}
$left = $baseline->where;
$right = $next->where;
sort($left);
sort($right);
checkOrderColumns($left === $right && $baseline->order_by === $next->order_by, 'Identical requests must retain identical meaning after reordering');

// Canonical date aliases support quick exports even if the date column is hidden.
$range = requestKeyTable($config, ['add_time_start'=>'2026-09-01 00:00:00', 'add_time_end'=>'2026-09-28 23:59:59', 'pay_price_min'=>'39.00', 'pay_price_max'=>'1580.00']);
$range->FormConfigHandle();
checkOrderColumns(count($range->where) === 4, 'Both bounds of named date and amount ranges must be applied');

foreach([
    ['f3p'=>'1', 'f1p_start'=>'2026-09-01 00:00:00', 'f1p_end'=>'2026-09-28 23:59:59'],
    ['f1p_start'=>'2026-09-01 00:00:00'],
    ['fp_order_by_key'=>'f1o'],
    ['order_by_key'=>'f52o'],
    ['f3p'=>['0'], 'form_table_is_export_excel'=>1],
    ['f3p'=>'0'],
    ['f3p'=>['1'], 'form_table_is_export_excel'=>1, 'pay_status'=>1],
] as $legacy)
{
    $stale = requestKeyTable($config, $legacy);
    checkOrderColumns($stale->FormRequestKeysError() !== '', 'Legacy parameters must never silently select a different field');
    $result = (new StaleOrderRequestProbe())->Run(OrderListColumnsFixture::class, 'Run', $legacy);
    checkOrderColumns($result['code'] !== 0 && strpos($result['msg'], '重新打开') !== false, 'Stale requests must stop with a recovery message before querying or exporting');
}

// Empty legacy controls impose no filter; do not block a fresh unfiltered request.
checkOrderColumns(requestKeyTable($config, ['f3p'=>'', 'f1p_start'=>'', 'f2p'=>[]])->FormRequestKeysError() === '', 'Empty old filters must be harmless');

// Other admin tables retain their current positional protocol.
$ordinary = $config;
unset($ordinary['base']['stable_request_keys']);
$ordinaryTable = requestKeyTable($ordinary, ['f3p'=>'1']);
checkOrderColumns($ordinaryTable->FormRequestKeysError() === '', 'Non-opt-in tables must keep accepting their existing request keys');
$ordinaryTable->FormConfigHandle();
$ordinaryFields = array_column($ordinaryTable->form_data['form'], null, 'view_key');
checkOrderColumns($ordinaryFields['status_name']['form_key'] === 'f3p', 'Unrelated tables must retain positional keys');

echo "Order request key tests passed (stable filters, sorting, ranges, stale request rejection, export guard, legacy tables).\n";
