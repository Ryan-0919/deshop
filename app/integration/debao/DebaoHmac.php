<?php

namespace app\integration\debao;

final class DebaoHmac
{
    public static function verify(array $headers, string $method, string $path, string $query, string $body, array $config, int $now): array
    {
        $clientId = trim((string)($headers['x-debao-client-id'] ?? ''));
        $keyId = trim((string)($headers['x-debao-key-id'] ?? ''));
        $timestamp = trim((string)($headers['x-debao-timestamp'] ?? ''));
        $nonce = trim((string)($headers['x-debao-nonce'] ?? ''));
        $signature = trim((string)($headers['x-debao-signature'] ?? ''));
        if($clientId === '' || $keyId === '' || $timestamp === '' || $nonce === '' || $signature === '')
        {
            return self::failure('AUTH_MISSING_HEADERS');
        }
        if(!ctype_digit($timestamp) || abs($now-(int)$timestamp) > (int)($config['clock_skew'] ?? 300))
        {
            return self::failure('AUTH_TIMESTAMP_OUT_OF_RANGE');
        }
        if($clientId !== (string)($config['client_id'] ?? ''))
        {
            return self::failure('AUTH_INVALID_SIGNATURE');
        }
        $secret = (string)($config['keys'][$keyId] ?? '');
        if($secret === '')
        {
            return self::failure('AUTH_INVALID_SIGNATURE');
        }
        $nonceKey = hash('sha256', $clientId.'\n'.$keyId.'\n'.$nonce);
        $canonical = implode("\n", [
            $clientId,
            $keyId,
            $timestamp,
            $nonce,
            strtoupper($method),
            $path,
            $query,
            hash('sha256', $body),
        ]);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');
        if(!hash_equals($expected, $signature))
        {
            return self::failure('AUTH_INVALID_SIGNATURE');
        }
        if(!DebaoNonceStore::claim($nonceKey, $now+(int)($config['nonce_ttl'] ?? 600), $now))
        {
            return self::failure('AUTH_REPLAY_DETECTED');
        }
        return ['code'=>0];
    }

    private static function failure(string $code): array
    {
        return ['code'=>-1, 'error'=>$code];
    }
}
