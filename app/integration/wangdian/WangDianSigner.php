<?php
namespace app\integration\wangdian;

final class WangDianSigner
{
    public static function sign(array $params, string $appSecret): string
    {
        unset($params['sign'], $params['appsecret']);
        ksort($params, SORT_STRING);

        $segments = [];
        foreach($params as $key => $value)
        {
            if(is_array($value) || is_object($value))
            {
                throw new \InvalidArgumentException('Wangdiantong signing parameters must be scalar values.');
            }

            $key = (string)$key;
            $value = $value === null ? '' : (string)$value;
            $segments[] = str_pad((string)strlen($key), 2, '0', STR_PAD_LEFT).'-'.$key
                .':'.str_pad((string)strlen($value), 4, '0', STR_PAD_LEFT).'-'.$value;
        }

        return md5(implode(';', $segments).$appSecret);
    }
}
