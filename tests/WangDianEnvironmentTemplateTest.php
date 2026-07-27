<?php

$template = file_get_contents(dirname(__DIR__).'/example.env');
if($template === false)
{
    throw new RuntimeException('The example environment file must be readable');
}

foreach([
    'WANGDIAN_AFTERSALE_BATCH_SIZE',
    'WANGDIAN_AFTERSALE_QUERY_LOOKBACK',
    'WANGDIAN_AFTERSALE_AUTO_PROCESS',
    'WANGDIAN_AFTERSALE_REFUNDMENT',
    'WANGDIAN_CRON_TOKEN',
    'WANGDIAN_CRON_ALLOWED_IPS',
] as $key)
{
    if(preg_match('/^'.preg_quote($key, '/').'\s*=/m', $template) !== 1)
    {
        throw new RuntimeException('example.env is missing '.$key);
    }
}

echo "WangDian environment template tests passed\n";
