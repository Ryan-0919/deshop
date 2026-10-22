<?php

namespace app\integration\debao;

final class DebaoBindingService
{
    public static function createChallenge(string $debaoUserId, string $method, array $config, int $now, ?DebaoBindingRepositoryInterface $repository = null): array
    {
        $debaoUserId = trim($debaoUserId);
        if(!self::validExternalId($debaoUserId) || !in_array($method, ['mini_qr', 'phone_fallback'], true))
        {
            return self::error('INVALID_REQUEST', 400);
        }
        if(empty($config['confirmation_enabled']))
        {
            return self::error('BINDING_CONFIRMATION_UNAVAILABLE', 503);
        }
        if($method === 'phone_fallback' && empty($config['phone_fallback_enabled']))
        {
            return self::error('BINDING_PROVIDER_NOT_CONFIGURED', 503);
        }

        $repository ??= new DebaoBindingDbRepository();
        if($repository->findActiveBindingByDebao($debaoUserId) !== null)
        {
            return self::error('BINDING_ALREADY_EXISTS', 409);
        }

        $challengeId = self::id('dbc_');
        $token = bin2hex(random_bytes(32));
        $expiresAt = $now+300;
        $repository->insertChallenge([
            'challenge_id'=>$challengeId,
            'debao_user_id'=>$debaoUserId,
            'deshop_user_id'=>0,
            'method'=>$method,
            'status'=>'pending',
            'token_hash'=>hash('sha256', $token),
            'confirmation_hash'=>'',
            'confirm_attempts'=>0,
            'expires_at'=>$expiresAt,
            'approved_at'=>0,
            'confirmed_at'=>0,
            'consumed_at'=>0,
            'add_time'=>$now,
            'upd_time'=>$now,
        ]);

        $baseUrl = trim((string)($config['challenge_url'] ?? ''));
        if($baseUrl === '')
        {
            $baseUrl = rtrim((string)($config['public_base_url'] ?? 'https://shop.denlaw.cn'), '/').'/index.php?s=debaobinding/index';
        }
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        $challengeUrl = $baseUrl.$separator.http_build_query(['challenge_id'=>$challengeId], '', '&', PHP_QUERY_RFC3986).'#token='.$token;

        return self::ok([
            'challenge_id'=>$challengeId,
            'status'=>'pending',
            'method'=>$method,
            'qr_url'=>$challengeUrl,
            'expires_at'=>gmdate('c', $expiresAt),
        ], 201);
    }

    public static function getChallenge(string $challengeId, string $debaoUserId, int $now, ?DebaoBindingRepositoryInterface $repository = null): array
    {
        if(!self::validChallengeId($challengeId) || !self::validExternalId($debaoUserId)) return self::error('NOT_FOUND', 404);
        $repository ??= new DebaoBindingDbRepository();
        $row = $repository->findChallenge($challengeId, $debaoUserId);
        if($row === null) return self::error('NOT_FOUND', 404);
        $row = self::expireIfNeeded($row, $now, $repository);
        return self::ok(self::publicChallenge($row));
    }

    public static function inspectChallenge(string $challengeId, string $token, int $now, ?DebaoBindingRepositoryInterface $repository = null): array
    {
        if(!self::validChallengeId($challengeId) || !self::validToken($token)) return self::error('INVALID_CONFIRMATION_TOKEN', 403);
        $repository ??= new DebaoBindingDbRepository();
        $row = $repository->findChallenge($challengeId);
        if($row === null || !hash_equals((string)$row['token_hash'], hash('sha256', $token))) return self::error('INVALID_CONFIRMATION_TOKEN', 403);
        $row = self::expireIfNeeded($row, $now, $repository);
        if($row['status'] === 'expired') return self::error('CHALLENGE_EXPIRED', 409);
        if($row['status'] !== 'pending') return self::error('CHALLENGE_ALREADY_USED', 409);
        return self::ok(['challenge_id'=>$challengeId, 'method'=>$row['method'], 'status'=>'pending', 'expires_at'=>gmdate('c', (int)$row['expires_at'])]);
    }

    public static function approveChallenge(string $challengeId, string $token, int $deshopUserId, int $now, ?DebaoBindingRepositoryInterface $repository = null): array
    {
        if($deshopUserId <= 0) return self::error('AUTH_REQUIRED', 401);
        if(!self::validChallengeId($challengeId) || !self::validToken($token)) return self::error('INVALID_CONFIRMATION_TOKEN', 403);
        $repository ??= new DebaoBindingDbRepository();

        return $repository->transaction(function(DebaoBindingRepositoryInterface $store) use($challengeId, $token, $deshopUserId, $now): array {
            $row = $store->findChallenge($challengeId, null, true);
            if($row === null || !hash_equals((string)$row['token_hash'], hash('sha256', $token))) return self::error('INVALID_CONFIRMATION_TOKEN', 403);
            if((int)$row['expires_at'] <= $now)
            {
                $store->updateChallenge((int)$row['id'], ['status'=>'expired', 'upd_time'=>$now]);
                return self::error('CHALLENGE_EXPIRED', 409);
            }
            if($row['status'] !== 'pending') return self::error('CHALLENGE_ALREADY_USED', 409);

            $byDebao = $store->findActiveBindingByDebao((string)$row['debao_user_id'], true);
            $byDeshop = $store->findActiveBindingByDeshop($deshopUserId, true);
            if($byDebao !== null || $byDeshop !== null) return self::error('BINDING_CONFLICT', 409);

            $confirmationCode = strtoupper(bin2hex(random_bytes(6)));
            $store->updateChallenge((int)$row['id'], [
                'deshop_user_id'=>$deshopUserId,
                'status'=>'approved',
                'confirmation_hash'=>hash('sha256', $confirmationCode),
                'approved_at'=>$now,
                'upd_time'=>$now,
            ]);
            return self::ok(['challenge_id'=>$challengeId, 'status'=>'approved', 'confirmation_code'=>$confirmationCode, 'expires_at'=>gmdate('c', (int)$row['expires_at'])]);
        });
    }

    public static function confirm(string $challengeId, string $debaoUserId, array $payload, array $config, int $now, ?DebaoBindingRepositoryInterface $repository = null): array
    {
        unset($config);
        $confirmationCode = strtoupper(trim((string)($payload['confirmation_code'] ?? '')));
        if(!self::validChallengeId($challengeId) || !self::validExternalId($debaoUserId) || !preg_match('/^[A-F0-9]{12}$/', $confirmationCode))
        {
            return self::error('INVALID_CONFIRMATION_CODE', 400);
        }
        $repository ??= new DebaoBindingDbRepository();

        try {
            return $repository->transaction(function(DebaoBindingRepositoryInterface $store) use($challengeId, $debaoUserId, $confirmationCode, $now): array {
                $row = $store->findChallenge($challengeId, $debaoUserId, true);
                if($row === null) return self::error('NOT_FOUND', 404);
                if($row['status'] === 'confirmed')
                {
                    if(!hash_equals((string)$row['confirmation_hash'], hash('sha256', $confirmationCode))) return self::error('INVALID_CONFIRMATION_CODE', 403);
                    $binding = $store->findActiveBindingByDebao($debaoUserId, true);
                    if($binding !== null && (int)$binding['deshop_user_id'] === (int)$row['deshop_user_id']) return self::bindingResult($binding);
                    return self::error('CHALLENGE_ALREADY_CONSUMED', 409);
                }
                if((int)$row['expires_at'] <= $now || $row['status'] === 'expired')
                {
                    if($row['status'] !== 'expired') $store->updateChallenge((int)$row['id'], ['status'=>'expired', 'upd_time'=>$now]);
                    return self::error('CHALLENGE_EXPIRED', 409);
                }
                if($row['status'] !== 'approved' || (int)$row['deshop_user_id'] <= 0) return self::error('CHALLENGE_NOT_APPROVED', 409);
                if(!hash_equals((string)$row['confirmation_hash'], hash('sha256', $confirmationCode)))
                {
                    $attempts = (int)$row['confirm_attempts']+1;
                    $store->updateChallenge((int)$row['id'], ['confirm_attempts'=>$attempts, 'status'=>$attempts >= 5 ? 'cancelled' : 'approved', 'upd_time'=>$now]);
                    return self::error('INVALID_CONFIRMATION_CODE', 403);
                }

                $deshopUserId = (int)$row['deshop_user_id'];
                if($store->findActiveBindingByDebao($debaoUserId, true) !== null || $store->findActiveBindingByDeshop($deshopUserId, true) !== null)
                {
                    return self::error('BINDING_CONFLICT', 409);
                }
                $bindingId = $store->insertBinding([
                    'debao_user_id'=>$debaoUserId,
                    'deshop_user_id'=>$deshopUserId,
                    'status'=>'active',
                    'revoked_at'=>0,
                    'add_time'=>$now,
                    'upd_time'=>$now,
                ]);
                $store->updateChallenge((int)$row['id'], [
                    'status'=>'confirmed',
                    'confirmed_at'=>$now,
                    'consumed_at'=>$now,
                    'upd_time'=>$now,
                ]);
                return self::bindingResult(['id'=>$bindingId, 'deshop_user_id'=>$deshopUserId]);
            });
        } catch(\Throwable $error) {
            if(str_contains($error->getMessage(), '1062') || str_contains($error->getMessage(), 'Duplicate entry')) return self::error('BINDING_CONFLICT', 409);
            throw $error;
        }
    }

    public static function status(string $debaoUserId, ?DebaoBindingRepositoryInterface $repository = null): array
    {
        if(!self::validExternalId($debaoUserId)) return self::error('INVALID_REQUEST', 400);
        $repository ??= new DebaoBindingDbRepository();
        $row = $repository->findActiveBindingByDebao($debaoUserId);
        return self::ok(['status'=>$row ? 'bound' : 'unbound', 'debao_user_id'=>$debaoUserId, 'deshop_user_id'=>$row ? (string)$row['deshop_user_id'] : null]);
    }

    public static function unbind(string $debaoUserId, int $now, ?DebaoBindingRepositoryInterface $repository = null): array
    {
        if(!self::validExternalId($debaoUserId)) return self::error('INVALID_REQUEST', 400);
        $repository ??= new DebaoBindingDbRepository();
        return $repository->transaction(function(DebaoBindingRepositoryInterface $store) use($debaoUserId, $now): array {
            $row = $store->findActiveBindingByDebao($debaoUserId, true);
            if($row === null) return ['code'=>0, 'status'=>204];
            $store->updateBinding((int)$row['id'], ['status'=>'revoked', 'revoked_at'=>$now, 'upd_time'=>$now]);
            return ['code'=>0, 'status'=>204];
        });
    }

    private static function expireIfNeeded(array $row, int $now, DebaoBindingRepositoryInterface $repository): array
    {
        if(in_array($row['status'], ['pending', 'approved'], true) && (int)$row['expires_at'] <= $now)
        {
            $repository->updateChallenge((int)$row['id'], ['status'=>'expired', 'upd_time'=>$now]);
            $row['status'] = 'expired';
        }
        return $row;
    }

    private static function publicChallenge(array $row): array
    {
        $status = $row['status'] === 'approved' ? 'confirmed' : $row['status'];
        $data = ['challenge_id'=>$row['challenge_id'], 'status'=>$status, 'method'=>$row['method'], 'expires_at'=>gmdate('c', (int)$row['expires_at'])];
        if(in_array($row['status'], ['approved', 'confirmed'], true) && (int)$row['deshop_user_id'] > 0) $data['deshop_user_id'] = (string)$row['deshop_user_id'];
        return $data;
    }

    private static function bindingResult(array $binding): array
    {
        return self::ok(['binding_id'=>'dbb_'.(string)$binding['id'], 'deshop_user_id'=>(string)$binding['deshop_user_id'], 'status'=>'active']);
    }

    private static function validExternalId(string $value): bool { return $value !== '' && strlen($value) <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1; }
    private static function validChallengeId(string $value): bool { return preg_match('/^dbc_[a-f0-9]{32}$/', $value) === 1; }
    private static function validToken(string $value): bool { return preg_match('/^[a-f0-9]{64}$/', $value) === 1; }
    private static function id(string $prefix): string { return $prefix.bin2hex(random_bytes(16)); }
    private static function ok(array $data, int $status = 200): array { return ['code'=>0, 'status'=>$status, 'data'=>$data]; }
    private static function error(string $code, int $status): array { return ['code'=>-1, 'status'=>$status, 'error'=>$code]; }
}
