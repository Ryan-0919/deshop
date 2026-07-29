<?php

$documentation = file_get_contents(dirname(__DIR__).'/docs/integrations/wangdian-erp.md');
if($documentation === false)
{
    throw new RuntimeException('The Wangdiantong integration documentation must be readable');
}

if(str_contains($documentation, 'WANGDIAN_EXPRESS_MAP={"YUNDA":6}'))
{
    throw new RuntimeException('The documentation must not recommend the obsolete YUNDA express code');
}

foreach([
    'YDKD001',
    'ZGYZ001',
    'YZXB001',
    'SF001',
    'XCX-SF',
    'YT001',
    'ZTKD001',
    'JDWL001',
    'JDCP001',
] as $code)
{
    if(!str_contains($documentation, '`'.$code.'`'))
    {
        throw new RuntimeException('The documentation is missing ERP express code '.$code);
    }
}

echo "WangDian express documentation tests passed\n";
