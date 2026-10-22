<?php

declare(strict_types=1);

require_once __DIR__.'/../app/integration/debao/DebaoCatalogService.php';

use app\integration\debao\DebaoCatalogService;

function invokeCatalog(string $method, ...$arguments)
{
    $reflection = new ReflectionMethod(DebaoCatalogService::class, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke(null, ...$arguments);
}

function assertCatalogSame($expected, $actual, string $message): void
{
    if($expected !== $actual) throw new RuntimeException($message.' expected='.var_export($expected, true).' actual='.var_export($actual, true));
}

assertCatalogSame(20, invokeCatalog('listQuery', [], ['cursor','limit'])['limit'], 'Default page size');
assertCatalogSame(100, invokeCatalog('listQuery', ['limit'=>'100'], ['cursor','limit'])['limit'], 'Maximum page size');
assertCatalogSame('INVALID_LIMIT', invokeCatalog('listQuery', ['limit'=>'0'], ['cursor','limit'])['error'], 'Zero page size');
assertCatalogSame('INVALID_LIMIT', invokeCatalog('listQuery', ['limit'=>'101'], ['cursor','limit'])['error'], 'Oversized page size');
assertCatalogSame('INVALID_LIMIT', invokeCatalog('listQuery', ['limit'=>'1.5'], ['cursor','limit'])['error'], 'Non-integer page size');
assertCatalogSame('INVALID_QUERY', invokeCatalog('listQuery', ['unknown'=>'1'], ['cursor','limit'])['error'], 'Unknown query parameter');
assertCatalogSame('INVALID_CURSOR', invokeCatalog('listQuery', ['cursor'=>'***'], ['cursor','limit'])['error'], 'Invalid cursor');
$cursor = invokeCatalog('encodeCursor', 123);
assertCatalogSame(123, invokeCatalog('decodeCursor', $cursor), 'Cursor round trip');
assertCatalogSame(123, invokeCatalog('positiveId', '123'), 'Valid resource id');
assertCatalogSame(null, invokeCatalog('positiveId', ''), 'Empty resource id');
assertCatalogSame(null, invokeCatalog('positiveId', 'abc'), 'Invalid resource id');
assertCatalogSame(0, invokeCatalog('utcTimestamp', '1970-01-01T00:00:00Z'), 'UTC timestamp');
assertCatalogSame(null, invokeCatalog('utcTimestamp', '2026-08-26 00:00:00'), 'Non-ISO timestamp');
assertCatalogSame(1234, invokeCatalog('cents', '12.34'), 'Integer cents');

$controller = file_get_contents(__DIR__.'/../app/api/controller/Debao.php');
$routes = file_get_contents(__DIR__.'/../app/api/route/debao.php');
$service = file_get_contents(__DIR__.'/../app/integration/debao/DebaoCatalogService.php');
if($controller === false || $routes === false || $service === false) throw new RuntimeException('Catalog source unavailable.');
foreach(['DebaoCatalogService::categories','DebaoCatalogService::products','DebaoCatalogService::product','DebaoCatalogService::skus'] as $call)
    if(!str_contains($controller, $call)) throw new RuntimeException('Missing controller adapter '.$call);
foreach(["Route::get('categories$'", "Route::get('products$'", "Route::get('products/<product_id>$'", "Route::get('products/<product_id>/skus$'"] as $route)
    if(!str_contains($routes, $route)) throw new RuntimeException('Missing route '.$route);
if(substr_count($routes, "->pattern(['product_id'=>'[\\w.-]+'])") !== 2) throw new RuntimeException('Product routes must pass malformed IDs to strict validation.');
foreach(['GoodsCategory','GoodsCategoryJoin','GoodsSpecBase','GoodsSpecType','GoodsSpecValue',"'g.is_shelves'=>1", "'g.is_delete_time'=>0"] as $source)
    if(!str_contains($service, $source)) throw new RuntimeException('Missing real ShopXO source/visibility filter '.$source);
if(str_contains($service, 'fixture') || str_contains($service, 'fake product')) throw new RuntimeException('Catalog adapter contains fake data.');

echo "DebaoCatalogContractTest: OK\n";
