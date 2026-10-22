<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__.'/../app/integration/debao/DebaoSessionService.php');
$controller = file_get_contents(__DIR__.'/../app/api/controller/Debao.php');
$routes = file_get_contents(__DIR__.'/../app/api/route/route.config');
$runtimeRoutes = file_get_contents(__DIR__.'/../app/api/route/debao.php');
$migration = file_get_contents(__DIR__.'/../scripts/sql/20260825_create_debao_session.sql');
if($service === false || $controller === false || $routes === false || $runtimeRoutes === false || $migration === false) throw new RuntimeException('Unable to read Debao session sources.');
foreach(['function sso','function checkout','function get','function consume','function payment','PAYMENT_PROVIDER_NOT_CONFIGURED','expires_at','consumed_at'] as $needle)
    if(!str_contains($service, $needle)) throw new RuntimeException("Missing session/payment behavior: {$needle}");
foreach(['ssosession','checkoutsession','debao/session','debao/orderpay'] as $needle)
    if(!str_contains($routes, $needle) || !str_contains($runtimeRoutes, $needle)) throw new RuntimeException("Missing runtime route: {$needle}");
foreach(['DebaoSessionService::sso','DebaoSessionService::checkout','DebaoSessionService::get','DebaoSessionService::payment'] as $needle)
    if(!str_contains($controller, $needle)) throw new RuntimeException("Missing controller adapter: {$needle}");
if(!str_contains($service, "'debao_user_id'=>\$id,'status'=>'active'") || !str_contains($service, "['id'=>\$id, 'user_id'=>\$userId]")) throw new RuntimeException('Ownership checks missing.');
if(!str_contains($migration, '`{PREFIX}debao_session`') || str_contains($migration, '`sxo_')) throw new RuntimeException('Invalid session migration template.');
echo "DebaoSessionContractTest: OK\n";
