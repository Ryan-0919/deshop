<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\debao\DebaoBindingDbRepository;
use app\integration\debao\DebaoBindingService;
use think\Container;
use think\DbManager;

function expectDatabaseBinding(string $label, mixed $expected, mixed $actual): void
{
    if($expected !== $actual) throw new RuntimeException($label.' expected='.var_export($expected, true).' actual='.var_export($actual, true));
}

function databaseChallengeCredentials(array $response): array
{
    $url = (string)$response['data']['qr_url'];
    parse_str((string)parse_url($url, PHP_URL_FRAGMENT), $fragment);
    return [(string)$response['data']['challenge_id'], (string)($fragment['token'] ?? '')];
}

$databaseConfig = require dirname(__DIR__).'/config/database.php';
$connection = $databaseConfig['connections']['mysql'] ?? [];
$host = (string)($connection['hostname'] ?? '');
$port = (int)($connection['hostport'] ?? 3306);
$username = (string)($connection['username'] ?? '');
$password = (string)($connection['password'] ?? '');
$allowedHosts = ['127.0.0.1', 'localhost', '::1'];
$testDatabase = 'shopxo_binding_test';
$prefix = 't_';

if(!in_array($host, $allowedHosts, true) || !str_ends_with($testDatabase, '_test'))
{
    throw new RuntimeException('Binding database test refuses to use a non-local or non-test database.');
}

$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$db = null;

try {
    $server->exec("DROP DATABASE IF EXISTS `{$testDatabase}`");
    $server->exec("CREATE DATABASE `{$testDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new PDO("mysql:host={$host};port={$port};dbname={$testDatabase};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);

    $migration = file_get_contents(dirname(__DIR__).'/scripts/sql/20260825_create_debao_binding.sql');
    if($migration === false) throw new RuntimeException('Unable to read binding migration.');
    $migration = str_replace(['{PREFIX}', '{CHARSET}'], [$prefix, 'utf8mb4'], $migration);
    foreach(preg_split('/;\s*(?:\r?\n|$)/', $migration, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) $db->exec($statement);

    $testConnection = $connection;
    $testConnection['database'] = $testDatabase;
    $testConnection['prefix'] = $prefix;
    $manager = new DbManager();
    $manager->setConfig(array_merge($databaseConfig, ['default'=>'mysql', 'connections'=>['mysql'=>$testConnection]]));
    Container::getInstance()->instance('think\DbManager', $manager);

    $repository = new DebaoBindingDbRepository();
    $config = ['public_base_url'=>'https://shop.denlaw.cn', 'confirmation_enabled'=>true];
    $created = DebaoBindingService::createChallenge('db-user-1', 'mini_qr', $config, 100, $repository);
    [$challengeId, $token] = databaseChallengeCredentials($created);
    $approved = DebaoBindingService::approveChallenge($challengeId, $token, 101, 120, $repository);
    $confirmationCode = (string)$approved['data']['confirmation_code'];
    $confirmed = DebaoBindingService::confirm($challengeId, 'db-user-1', ['confirmation_code'=>$confirmationCode], [], 121, $repository);
    expectDatabaseBinding('Database confirmation', 'active', $confirmed['data']['status']);

    $challenge = $db->query("SELECT token_hash,confirmation_hash,status FROM `{$prefix}debao_binding_challenge` WHERE challenge_id=".$db->quote($challengeId))->fetch(PDO::FETCH_ASSOC);
    expectDatabaseBinding('Stored token hash', hash('sha256', $token), $challenge['token_hash'] ?? null);
    expectDatabaseBinding('Stored confirmation hash', hash('sha256', $confirmationCode), $challenge['confirmation_hash'] ?? null);
    expectDatabaseBinding('Stored challenge status', 'confirmed', $challenge['status'] ?? null);

    $duplicateRejected = false;
    try {
        $statement = $db->prepare("INSERT INTO `{$prefix}debao_binding` (debao_user_id,deshop_user_id,status,revoked_at,add_time,upd_time) VALUES (?,?,?,?,?,?)");
        $statement->execute(['db-user-2', 101, 'active', 0, 122, 122]);
    } catch(PDOException $error) {
        $duplicateRejected = $error->getCode() === '23000';
    }
    expectDatabaseBinding('Database unique ownership guard', true, $duplicateRejected);

    expectDatabaseBinding('Database unbind', 204, DebaoBindingService::unbind('db-user-1', 130, $repository)['status']);
    expectDatabaseBinding('Database repeated unbind', 204, DebaoBindingService::unbind('db-user-1', 131, $repository)['status']);

    $replacement = DebaoBindingService::createChallenge('db-user-1', 'mini_qr', $config, 140, $repository);
    [$replacementId, $replacementToken] = databaseChallengeCredentials($replacement);
    $replacementApproval = DebaoBindingService::approveChallenge($replacementId, $replacementToken, 101, 141, $repository);
    expectDatabaseBinding('Rebind after revoke', 0, $replacementApproval['code']);

    $manager->connect()->close();
    $db = null;
    echo "DebaoBindingDatabaseIntegrationTest: OK\n";
} finally {
    $db = null;
    $server->exec("DROP DATABASE IF EXISTS `{$testDatabase}`");
}
