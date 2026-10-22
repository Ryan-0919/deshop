<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__.'/../app/integration/debao/DebaoAddressService.php');
$controller = file_get_contents(__DIR__.'/../app/api/controller/Debao.php');
$routes = file_get_contents(__DIR__.'/../app/api/route/route.config');
if($service === false || $controller === false || $routes === false) throw new RuntimeException('Unable to read Debao address sources.');
foreach(['function list', 'function create', 'function update', 'function delete', 'function setDefault'] as $method)
    if(!str_contains($service, $method)) throw new RuntimeException("Missing address method: {$method}");
foreach(['addresses', 'addressescreate', 'addressupdate', 'addressdelete', 'addressdefault'] as $route)
    if(!str_contains($routes, "debao/{$route}")) throw new RuntimeException("Missing address route: {$route}");
foreach(['DebaoAddressService::list', 'DebaoAddressService::create', 'DebaoAddressService::update', 'DebaoAddressService::delete', 'DebaoAddressService::setDefault'] as $call)
    if(!str_contains($controller, $call)) throw new RuntimeException("Missing controller call: {$call}");
foreach(['BINDING_REQUIRED', "['debao_user_id'=>\$debaoUserId, 'status'=>'active']", "['id'=>\$id, 'user_id'=>\$userId]"] as $guard)
    if(!str_contains($service, $guard)) throw new RuntimeException("Missing ownership guard: {$guard}");
foreach(['idcard_number', 'idcard_front', 'idcard_back', 'error_log', 'Log::'] as $forbidden)
    if(str_contains($service, $forbidden)) throw new RuntimeException("Sensitive data or logging found: {$forbidden}");
if(!str_contains($service, "'phone'=>(string)\$row['tel']") || !str_contains($service, "'detail'=>(string)\$row['address']"))
    throw new RuntimeException('Public address mapping is incomplete.');
echo "DebaoAddressContractTest: OK\n";
