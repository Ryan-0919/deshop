<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\debao\DebaoBindingRepositoryInterface;
use app\integration\debao\DebaoBindingService;

final class MemoryBindingRepository implements DebaoBindingRepositoryInterface
{
    public array $challenges = [];
    public array $bindings = [];
    private int $challengeSequence = 1;
    private int $bindingSequence = 1;

    public function transaction(callable $callback): array { return $callback($this); }
    public function insertChallenge(array $data): void { $data['id'] = $this->challengeSequence++; $this->challenges[$data['challenge_id']] = $data; }
    public function findChallenge(string $challengeId, ?string $debaoUserId = null, bool $forUpdate = false): ?array
    {
        unset($forUpdate);
        $row = $this->challenges[$challengeId] ?? null;
        return $row !== null && ($debaoUserId === null || $row['debao_user_id'] === $debaoUserId) ? $row : null;
    }
    public function updateChallenge(int $id, array $data): void
    {
        foreach($this->challenges as $key=>$row) if((int)$row['id'] === $id) { $this->challenges[$key] = array_merge($row, $data); return; }
        throw new RuntimeException('Challenge not found.');
    }
    public function findActiveBindingByDebao(string $debaoUserId, bool $forUpdate = false): ?array
    {
        unset($forUpdate);
        foreach($this->bindings as $row) if($row['status'] === 'active' && $row['debao_user_id'] === $debaoUserId) return $row;
        return null;
    }
    public function findActiveBindingByDeshop(int $deshopUserId, bool $forUpdate = false): ?array
    {
        unset($forUpdate);
        foreach($this->bindings as $row) if($row['status'] === 'active' && (int)$row['deshop_user_id'] === $deshopUserId) return $row;
        return null;
    }
    public function insertBinding(array $data): int
    {
        $data['id'] = $this->bindingSequence++;
        $this->bindings[$data['id']] = $data;
        return $data['id'];
    }
    public function updateBinding(int $id, array $data): void
    {
        if(!isset($this->bindings[$id])) throw new RuntimeException('Binding not found.');
        $this->bindings[$id] = array_merge($this->bindings[$id], $data);
    }
}

function expectBinding(string $label, $expected, $actual): void
{
    if($expected !== $actual) throw new RuntimeException($label.' expected='.var_export($expected, true).' actual='.var_export($actual, true));
}

function challengeCredentials(array $response): array
{
    $url = $response['data']['qr_url'];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    parse_str((string)parse_url($url, PHP_URL_FRAGMENT), $fragment);
    return [$response['data']['challenge_id'], $fragment['token'] ?? '', $query];
}

$disabledConfig = ['public_base_url'=>'https://shop.denlaw.cn', 'confirmation_enabled'=>false];
$store = new MemoryBindingRepository();
expectBinding('Confirmation fails closed when disabled', 'BINDING_CONFIRMATION_UNAVAILABLE', DebaoBindingService::createChallenge('debao-disabled', 'mini_qr', $disabledConfig, 100, $store)['error']);

$config = ['public_base_url'=>'https://shop.denlaw.cn', 'confirmation_enabled'=>true];
$store = new MemoryBindingRepository();
expectBinding('Phone fallback fails closed without SMS provider', 'BINDING_PROVIDER_NOT_CONFIGURED', DebaoBindingService::createChallenge('debao-phone', 'phone_fallback', $config, 100, $store)['error']);
$created = DebaoBindingService::createChallenge('debao-user-1', 'mini_qr', $config, 100, $store);
expectBinding('Challenge create status', 201, $created['status']);
[$challengeId, $token, $query] = challengeCredentials($created);
expectBinding('Challenge id query', $challengeId, $query['challenge_id'] ?? null);
expectBinding('Challenge page path', '/index.php', parse_url($created['data']['qr_url'], PHP_URL_PATH));
expectBinding('Challenge page route', 'debaobinding/index', $query['s'] ?? null);
expectBinding('Token fragment length', 64, strlen($token));
$stored = $store->challenges[$challengeId];
expectBinding('Only token hash is stored', hash('sha256', $token), $stored['token_hash']);
if(str_contains(json_encode($stored, JSON_THROW_ON_ERROR), $token)) throw new RuntimeException('Plain challenge token was stored.');

expectBinding('Wrong token', 'INVALID_CONFIRMATION_TOKEN', DebaoBindingService::approveChallenge($challengeId, str_repeat('0', 64), 10, 120, $store)['error']);
expectBinding('Unauthenticated approval', 'AUTH_REQUIRED', DebaoBindingService::approveChallenge($challengeId, $token, 0, 120, $store)['error']);
$approved = DebaoBindingService::approveChallenge($challengeId, $token, 10, 120, $store);
expectBinding('Approval succeeds', 0, $approved['code']);
$confirmationCode = $approved['data']['confirmation_code'];
expectBinding('Confirmation code format', 1, preg_match('/^[A-F0-9]{12}$/', $confirmationCode));
expectBinding('Confirmation hash stored', hash('sha256', $confirmationCode), $store->challenges[$challengeId]['confirmation_hash']);
expectBinding('Token consumed once', 'CHALLENGE_ALREADY_USED', DebaoBindingService::approveChallenge($challengeId, $token, 10, 121, $store)['error']);
$challengeStatus = DebaoBindingService::getChallenge($challengeId, 'debao-user-1', 121, $store);
expectBinding('Approved status is public confirmed', 'confirmed', $challengeStatus['data']['status']);
expectBinding('Status returns actual ShopXO user', '10', $challengeStatus['data']['deshop_user_id']);
expectBinding('Wrong Debao user cannot confirm', 'NOT_FOUND', DebaoBindingService::confirm($challengeId, 'debao-user-2', ['confirmation_code'=>$confirmationCode], [], 130, $store)['error']);
expectBinding('Wrong confirmation code', 'INVALID_CONFIRMATION_CODE', DebaoBindingService::confirm($challengeId, 'debao-user-1', ['confirmation_code'=>'AAAAAAAAAAAA'], [], 130, $store)['error']);
$confirmed = DebaoBindingService::confirm($challengeId, 'debao-user-1', ['confirmation_code'=>$confirmationCode], [], 131, $store);
expectBinding('Confirmation succeeds', 'active', $confirmed['data']['status']);
expectBinding('Bound ShopXO user', '10', $confirmed['data']['deshop_user_id']);
expectBinding('Confirmed challenge rejects a different code', 'INVALID_CONFIRMATION_CODE', DebaoBindingService::confirm($challengeId, 'debao-user-1', ['confirmation_code'=>'BBBBBBBBBBBB'], [], 132, $store)['error']);
$repeated = DebaoBindingService::confirm($challengeId, 'debao-user-1', ['confirmation_code'=>$confirmationCode], [], 132, $store);
expectBinding('Repeated confirmation is idempotent', $confirmed['data']['binding_id'], $repeated['data']['binding_id']);
expectBinding('Binding status', 'bound', DebaoBindingService::status('debao-user-1', $store)['data']['status']);
expectBinding('Unbind response', 204, DebaoBindingService::unbind('debao-user-1', 140, $store)['status']);
expectBinding('Unbind revokes only mapping', 'revoked', $store->bindings[1]['status']);
expectBinding('Repeated unbind is idempotent', 204, DebaoBindingService::unbind('debao-user-1', 141, $store)['status']);
expectBinding('Status after unbind', 'unbound', DebaoBindingService::status('debao-user-1', $store)['data']['status']);

$expiredStore = new MemoryBindingRepository();
$expired = DebaoBindingService::createChallenge('debao-expired', 'mini_qr', $config, 100, $expiredStore);
[$expiredId, $expiredToken] = challengeCredentials($expired);
expectBinding('Challenge expires at the exact boundary', 'CHALLENGE_EXPIRED', DebaoBindingService::approveChallenge($expiredId, $expiredToken, 20, 400, $expiredStore)['error']);

$conflictStore = new MemoryBindingRepository();
$conflict = DebaoBindingService::createChallenge('debao-conflict', 'mini_qr', $config, 100, $conflictStore);
[$conflictId, $conflictToken] = challengeCredentials($conflict);
$conflictStore->insertBinding(['debao_user_id'=>'someone-else','deshop_user_id'=>30,'status'=>'active','revoked_at'=>0,'add_time'=>100,'upd_time'=>100]);
expectBinding('ShopXO ownership conflict', 'BINDING_CONFLICT', DebaoBindingService::approveChallenge($conflictId, $conflictToken, 30, 120, $conflictStore)['error']);

echo "DebaoBindingWorkflowTest: OK\n";
