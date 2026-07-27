<?php

$sql = file_get_contents(dirname(__DIR__).'/scripts/sql/20260724_create_wangdian_sync_log.sql');
if($sql === false)
{
    throw new RuntimeException('The Wangdiantong migration template must be readable');
}
if(!str_contains($sql, '`{PREFIX}wangdian_sync_log`'))
{
    throw new RuntimeException('The Wangdiantong migration must use the ShopXO table-prefix placeholder');
}
if(str_contains($sql, '`sxo_wangdian_sync_log`'))
{
    throw new RuntimeException('The Wangdiantong migration must not hard-code the default ShopXO prefix');
}

$cursorSql = file_get_contents(dirname(__DIR__).'/scripts/sql/20260727_create_wangdian_sync_cursor.sql');
if($cursorSql === false)
{
    throw new RuntimeException('The Wangdiantong cursor migration template must be readable');
}
foreach([
    '`{PREFIX}wangdian_sync_cursor`',
    '`{PREFIX}order_aftersale` ADD INDEX `wangdian_upd_id` (`upd_time`, `id`)',
    '`{PREFIX}order_aftersale` ADD INDEX `wangdian_detail_id` (`order_detail_id`)',
    '`{PREFIX}order` ADD INDEX `wangdian_upd_id` (`upd_time`, `id`)',
    '`{PREFIX}order_detail` ADD INDEX `wangdian_upd_id` (`upd_time`, `id`)',
    '`{PREFIX}wangdian_sync_log` ADD INDEX `business_status_upd` (`business_type`, `status`, `upd_time`, `id`)',
    'information_schema.statistics',
] as $requiredSql)
{
    if(!str_contains($cursorSql, $requiredSql))
    {
        throw new RuntimeException('The Wangdiantong cursor migration is missing '.$requiredSql);
    }
}
if(str_contains($cursorSql, '`sxo_'))
{
    throw new RuntimeException('The Wangdiantong cursor migration must not hard-code the default ShopXO prefix');
}
if(substr_count($cursorSql, 'information_schema.statistics') < 5)
{
    throw new RuntimeException('Every Wangdiantong index migration must guard against an existing index');
}

echo "WangDian migration template tests passed\n";
