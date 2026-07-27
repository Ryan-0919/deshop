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
            '/\b([a-z0-9_-]*(?:appsecret|access[_-]?key[_-]?secret|secret|password|passwd|token|sign)[a-z0-9_-]*)\s*[:=：]\s*([^,;，；\s]+)/iu',
            '$1='.self::REDACTED,
            $value
        ) ?? '';
        $value = preg_replace(
            '/(收货地址|详细地址|地址|receiver[_-]?address|address)\s*[:=：]\s*([^,;，；\r\n]+)/iu',
            '$1='.self::REDACTED,
            $value
        ) ?? '';
        return trim($value);
    }

    private static function isSensitiveKey(string $key): bool
    {
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
