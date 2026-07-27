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

echo "WangDian migration template tests passed\n";
