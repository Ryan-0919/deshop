<?php
namespace app\integration\wangdian;

final class WangDianLogSanitizer
{
    private const REDACTED = '[REDACTED]';

    public static function sanitize($value)
    {
        if(is_array($value))
        {
            $result = [];
            foreach($value as $key=>$item)
            {
                $result[$key] = self::isSensitiveKey((string)$key)
                    ? self::REDACTED
                    : self::sanitize($item);
            }
            return $result;
        }
        if(is_string($value))
        {
            return self::sanitizeText($value);
        }
        return $value;
    }

    public static function sanitizeText(string $value): string
    {
        $json = self::sanitizeJsonText($value);
        if($json !== null)
        {
            return $json;
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = preg_replace(
            '/(?<!\d)(1[3-9]\d)\d{4}(\d{4})(?!\d)/u',
            '$1****$2',
            $value
        ) ?? '';
        $value = preg_replace(
            '/["\']([a-z0-9_-]*(?:appsecret|access[_-]?key[_-]?secret|secret|password|passwd|token|sign)[a-z0-9_-]*)["\']\s*:\s*(["\']).*?\2/iu',
            '$1='.self::REDACTED,
            $value
        ) ?? '';
        $value = preg_replace(
            '/["\']([a-z0-9_-]*address[a-z0-9_-]*)["\']\s*:\s*(["\']).*?\2/iu',
            '$1='.self::REDACTED,
            $value
        ) ?? '';
        $value = preg_replace(
            '/\b([a-z0-9_-]*(?:appsecret|access[_-]?key[_-]?secret|secret|password|passwd|token|sign)[a-z0-9_-]*)\s*[:=：]\s*([^,;，；\s]+)/iu',
            '$1='.self::REDACTED,
            $value
        ) ?? '';
        $value = preg_replace(
            '/(收货地址|详细地址|地址|receiver[_-]?address|address)\s*[:=：]\s*([^,;，；\r\n]+)/iu',
            '$1='.self::REDACTED,
            $value
        ) ?? '';
        $value = preg_replace(
            '/(收货地址|详细地址|地址)\s*[:=：]\s*([^,;，；\r\n]+)/u',
            '$1='.self::REDACTED,
            $value
        ) ?? '';
        return trim($value);
    }

    private static function sanitizeJsonText(string $value): ?string
    {
        $trimmed = trim($value);
        foreach(array_unique([$trimmed, stripcslashes($trimmed)]) as $candidate)
        {
            if($candidate === '' || !in_array($candidate[0], ['{', '['], true))
            {
                continue;
            }
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch(\JsonException $e) {
                continue;
            }
            if(!is_array($decoded))
            {
                continue;
            }
            return (string)json_encode(
                self::sanitize($decoded),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        return null;
    }

    private static function isSensitiveKey(string $key): bool
    {
        if(preg_match('/地址|收货地址|详细地址/u', $key) === 1)
        {
            return true;
        }
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $key) ?? '');
        foreach([
            'appsecret', 'accesskeysecret', 'secret', 'password', 'passwd', 'token', 'sign',
            'mobile', 'phone', 'telephone', 'tel', 'address', 'receiveraddress', 'receivermobile',
        ] as $sensitive)
        {
            if(str_contains($normalized, $sensitive))
            {
                return true;
            }
        }
        return false;
    }
}
