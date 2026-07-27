<?php
namespace app\integration\wangdian;

final class WangDianCronAccessPolicy
{
    public static function allows(string $remoteIp, string $providedToken, string $configuredToken, array $allowedIps): bool
    {
        $remoteIp = trim($remoteIp);
        $configuredToken = trim($configuredToken);
        $providedToken = trim($providedToken);

        if($configuredToken !== '' && $providedToken !== '' && hash_equals($configuredToken, $providedToken))
        {
            return true;
        }

        $normalizedIps = array_values(array_unique(array_filter(array_map(
            static fn($ip): string => trim((string)$ip),
            $allowedIps
        ), static fn(string $ip): bool => $ip !== '')));
        return $remoteIp !== '' && in_array($remoteIp, $normalizedIps, true);
    }
}
