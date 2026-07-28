<?php

$root = dirname(__DIR__);
$expectations = [
    'app/integration/wangdian/WangDianOrderStoreWorkflow.php' => "->field('odc.id')",
    'app/integration/wangdian/WangDianAftersaleStoreWorkflow.php' => "->field('opl.id')",
];

foreach($expectations as $relativePath => $safeProjection)
{
    $source = file_get_contents($root.'/'.$relativePath);
    if($source === false)
    {
        throw new RuntimeException('The Wangdiantong workflow source must be readable: '.$relativePath);
    }
    if(str_contains($source, "->field('1')"))
    {
        throw new RuntimeException(
            $relativePath.' uses field(1), which ThinkORM quotes as the nonexistent column `1`.'
        );
    }
    if(!str_contains($source, $safeProjection))
    {
        throw new RuntimeException($relativePath.' must project an existing non-null id in its EXISTS subquery.');
    }
}

echo "WangDian EXISTS projection tests passed\n";
