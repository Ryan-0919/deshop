<?php

declare(strict_types=1);

// 本测试只允许连接本机、名称带 _test 的临时数据库。
$databaseConfig = require dirname(__DIR__).'/config/database.php';
$connection = $databaseConfig['connections']['mysql'] ?? [];
$host = (string)($connection['hostname'] ?? '');
$port = (int)($connection['hostport'] ?? 3306);
$username = (string)($connection['username'] ?? '');
$password = (string)($connection['password'] ?? '');
$testDatabase = 'shopxo_price_test';
if(!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Refusing non-local database');

function MyLang($key, $params = []) { return (string) $key; }
function MyConst($key) { return [0=>['name'=>'待确认'], 1=>['name'=>'待付款']]; }
function MyEventTrigger($hook, $params = []) { return []; }
function DataReturn($msg = '', $code = 0, $data = '') { return ['msg'=>$msg, 'code'=>$code, 'data'=>$data]; }
function PriceNumberFormat($value, $decimal = 2) { return number_format((float) $value, $decimal, '.', ''); }

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\OrderService;
use think\Container;
use think\DbManager;

$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db = null;
try {
    $server->exec("DROP DATABASE IF EXISTS `{$testDatabase}`");
    $server->exec("CREATE DATABASE `{$testDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new PDO("mysql:host={$host};port={$port};dbname={$testDatabase};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE sxo_order (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT, order_no CHAR(60) NOT NULL, user_id INT UNSIGNED NOT NULL DEFAULT 0, status TINYINT UNSIGNED NOT NULL DEFAULT 1, pay_status TINYINT UNSIGNED NOT NULL DEFAULT 0, total_price DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00, pay_price DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00, upd_time INT UNSIGNED NOT NULL DEFAULT 0)");
    $db->exec("CREATE TABLE sxo_order_price_adjustment (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, order_id INT UNSIGNED NOT NULL, order_no CHAR(60) NOT NULL, old_total_price DECIMAL(10,2) NOT NULL, new_total_price DECIMAL(10,2) NOT NULL, reason VARCHAR(255) NOT NULL, creator INT UNSIGNED NOT NULL DEFAULT 0, creator_name VARCHAR(60) NOT NULL DEFAULT '', add_time INT UNSIGNED NOT NULL DEFAULT 0)");
    $db->exec("CREATE TABLE sxo_order_status_history (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, order_id INT UNSIGNED NOT NULL, new_status INT NOT NULL DEFAULT 0, original_status INT NOT NULL DEFAULT 0, msg TEXT, creator INT UNSIGNED NOT NULL DEFAULT 0, creator_name VARCHAR(60) NOT NULL DEFAULT '', add_time INT UNSIGNED NOT NULL DEFAULT 0)");
    $db->exec("CREATE TABLE sxo_pay_log (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, log_no CHAR(60) NOT NULL, business_type CHAR(180) NOT NULL DEFAULT '', status TINYINT UNSIGNED NOT NULL DEFAULT 0, close_time INT UNSIGNED NOT NULL DEFAULT 0)");
    $db->exec("CREATE TABLE sxo_pay_log_value (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT, pay_log_id BIGINT UNSIGNED NOT NULL, business_id INT UNSIGNED NOT NULL, business_no CHAR(60) NOT NULL DEFAULT '')");
    $db->exec("INSERT INTO sxo_order (id, order_no, user_id, status, pay_status, total_price) VALUES (101, 'TEST-101', 7, 1, 0, 100.00)");
    $db->exec("INSERT INTO sxo_pay_log (id, log_no, business_type, status) VALUES (201, 'PAY-201', 'order', 0)");
    $db->exec("INSERT INTO sxo_pay_log_value (pay_log_id, business_id, business_no) VALUES (201, 101, 'TEST-101')");

    $testConnection = $connection;
    $testConnection['database'] = $testDatabase;
    $testConnection['prefix'] = 'sxo_';
    $manager = new DbManager();
    $manager->setConfig(array_merge($databaseConfig, ['default'=>'mysql', 'connections'=>['mysql'=>$testConnection]]));
    Container::getInstance()->instance('think\\DbManager', $manager);

    $result = OrderService::OrderPriceAdjustment([
        'id'=>101,
        'new_total_price'=>'79.90',
        'reason'=>'客服议价优惠',
        'creator'=>1,
        'creator_name'=>'admin',
    ]);
    if(($result['code'] ?? -1) !== 0) throw new RuntimeException('Adjustment failed: '.($result['msg'] ?? 'unknown'));
    $order = $db->query('SELECT status,pay_status,total_price FROM sxo_order WHERE id=101')->fetch(PDO::FETCH_ASSOC);
    if((string) $order['total_price'] !== '79.90' || (int) $order['status'] !== 1 || (int) $order['pay_status'] !== 0) throw new RuntimeException('Order amount/state mismatch: '.var_export($order, true));
    $payLog = $db->query('SELECT status,close_time FROM sxo_pay_log WHERE id=201')->fetch(PDO::FETCH_ASSOC);
    if((int) $payLog['status'] !== 2 || (int) $payLog['close_time'] <= 0) throw new RuntimeException('Pending payment log was not closed');
    if((int) $db->query('SELECT COUNT(*) FROM sxo_order_price_adjustment WHERE order_id=101')->fetchColumn() !== 1) throw new RuntimeException('Audit row missing');
    if((int) $db->query('SELECT COUNT(*) FROM sxo_order_status_history WHERE order_id=101')->fetchColumn() !== 1) throw new RuntimeException('Order history row missing');

    $paid = $db->exec("UPDATE sxo_order SET pay_status=1, status=2 WHERE id=101");
    $rejected = OrderService::OrderPriceAdjustment(['id'=>101, 'new_total_price'=>'50.00', 'reason'=>'invalid', 'creator'=>1, 'creator_name'=>'admin']);
    if(($rejected['code'] ?? 0) === 0) throw new RuntimeException('Paid order adjustment must be rejected');
    echo "OrderPriceAdjustmentDatabaseTest: OK\n";
} finally {
    $db = null;
    $server->exec("DROP DATABASE IF EXISTS `{$testDatabase}`");
}
