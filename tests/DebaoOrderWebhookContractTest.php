<?php
declare(strict_types=1);
$s=file_get_contents(__DIR__.'/../app/integration/debao/DebaoOrderService.php');$r=file_get_contents(__DIR__.'/../app/api/route/route.config');$m=file_get_contents(__DIR__.'/../scripts/sql/20260825_create_debao_webhook_event.sql');
foreach(['function list','function detail','function logistics','function webhook','event_id','BINDING_REQUIRED'] as $n)if(!str_contains($s,$n))throw new RuntimeException($n);
foreach(['debao/orders','debao/order','debao/orderlogistics','debao/webhook'] as $n)if(!str_contains($r,$n))throw new RuntimeException($n);if(!str_contains($m,'UNIQUE KEY `event_id`'))throw new RuntimeException('idempotency');echo "DebaoOrderWebhookContractTest: OK\n";
