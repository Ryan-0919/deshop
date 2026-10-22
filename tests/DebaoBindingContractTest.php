<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$sql = file_get_contents(dirname(__DIR__).'/scripts/sql/20260825_create_debao_binding.sql');
if($sql === false) throw new RuntimeException('Unable to read binding migration.');
foreach(['`{PREFIX}debao_binding`','`{PREFIX}debao_binding_challenge`','`confirmation_hash`','`deshop_user_id`','`consumed_at`','`active_debao_user_id`','`active_deshop_user_id`'] as $required)
    if(!str_contains($sql, $required)) throw new RuntimeException('Binding migration is missing '.$required);
if(str_contains($sql, '`sxo_')) throw new RuntimeException('Migration must not hard-code table prefix.');

$service = file_get_contents(dirname(__DIR__).'/app/integration/debao/DebaoBindingService.php');
$apiRoutes = file_get_contents(dirname(__DIR__).'/app/api/route/debao.php');
$page = file_get_contents(dirname(__DIR__).'/app/index/controller/Debaobinding.php');
$view = file_get_contents(dirname(__DIR__).'/app/index/view/default/debaobinding/index.html');
if($service === false || $apiRoutes === false || $page === false || $view === false) throw new RuntimeException('Binding source is unavailable.');
foreach(["Route::post('bindings/challenges$'", "Route::get('bindings/challenges/<challenge_id>$'", "Route::post('bindings/challenges/<challenge_id>/confirm$'", "Route::get('bindings/status$'", "Route::delete('bindings$'"] as $route)
    if(!str_contains($apiRoutes, $route)) throw new RuntimeException('Binding route is missing: '.$route);
if(str_contains($service, 'UserService::UserInfo') || str_contains($service, "['mobile']")) throw new RuntimeException('Binding must not auto-merge users by phone.');
if(!str_contains($service, "hash('sha256', \$token)") || !str_contains($service, "hash('sha256', \$confirmationCode)")) throw new RuntimeException('Binding tokens must be stored as hashes.');
if(!str_contains($page, "!empty(\$this->user['id'])") || !str_contains($page, 'csrf_token')) throw new RuntimeException('Confirmation page must require the current login and CSRF confirmation.');
if(!str_contains($page, "Referrer-Policy: same-origin")) throw new RuntimeException('Confirmation page must preserve the same-origin login return path without leaking the challenge to third parties.');
if(!str_contains($view, 'sessionStorage') || !str_contains($view, '确认关联')) throw new RuntimeException('Confirmation page must preserve the fragment token and require an explicit action.');
if(substr_count($view, 'id="debao-challenge-id"') !== 1 || strpos($view, 'id="debao-challenge-id"') > strpos($view, '{{if !empty($binding_result)}}')) throw new RuntimeException('Challenge id must be available before login so the fragment token survives the login round trip.');

echo "DebaoBindingContractTest: OK\n";
