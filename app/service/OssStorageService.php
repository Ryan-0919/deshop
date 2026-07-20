<?php
namespace app\service;

use app\storage\OssHttpTransport;
use app\storage\OssStorage;

class OssStorageService
{
    private static ?OssStorage $storage = null;

    public static function IsEnabled(): bool
    {
        return (bool)MyConfig('oss.enabled');
    }

    public static function IsManagedPath(string $path): bool
    {
        try
        {
            $path = self::RelativePath($path);
            return str_starts_with($path, '/static/upload/');
        } catch(\InvalidArgumentException $e) {
            return false;
        }
    }

    public static function Upload(string $localFile, string $relativePath): string
    {
        if(!self::IsEnabled() || !self::IsManagedPath($relativePath))
        {
            return $relativePath;
        }
        $url = self::Storage()->put($localFile, $relativePath);
        self::WriteSyncMarker($relativePath, $localFile);
        return $url;
    }

    public static function Delete(string $path): void
    {
        if(self::IsEnabled() && self::IsManagedPath($path))
        {
            self::Storage()->delete($path);
            self::DeleteSyncMarker($path);
        }
    }

    public static function PublicUrl(string $path, string $fallbackHost = ''): string
    {
        if(!self::IsEnabled() || !self::IsManagedPath($path))
        {
            return $path;
        }

        try
        {
            self::EnsureLocalFileIsSynced($path);
            return self::Storage()->publicUrl($path);
        } catch(\Throwable $e) {
            error_log('ShopXO OSS lazy sync failed: '.$e->getMessage());
            return $fallbackHost === '' ? $path : rtrim($fallbackHost, '/').self::RelativePath($path);
        }
    }

    public static function PublicBaseUrl(): string
    {
        return rtrim((string)MyConfig('oss.public_url'), '/');
    }

    public static function RelativePath(string $path): string
    {
        return '/'.OssStorage::normalizeObjectPath($path);
    }

    private static function Storage(): OssStorage
    {
        if(self::$storage === null)
        {
            $config = MyConfig('oss');
            self::$storage = new OssStorage($config, new OssHttpTransport($config));
        }
        return self::$storage;
    }

    private static function EnsureLocalFileIsSynced(string $relativePath): void
    {
        $localFile = self::LocalFile($relativePath);
        if(!is_file($localFile) || self::SyncMarkerIsCurrent($relativePath, $localFile))
        {
            return;
        }

        self::Storage()->put($localFile, $relativePath);
        self::WriteSyncMarker($relativePath, $localFile);
    }

    private static function LocalFile(string $relativePath): string
    {
        $publicRoot = defined('ROOT') ? ROOT.'public' : dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public';
        return rtrim($publicRoot, '/\\').str_replace('/', DIRECTORY_SEPARATOR, self::RelativePath($relativePath));
    }

    private static function SyncMarkerIsCurrent(string $relativePath, string $localFile): bool
    {
        $marker = self::SyncMarkerFile($relativePath);
        return is_file($marker) && trim((string)file_get_contents($marker)) === self::LocalFileVersion($localFile);
    }

    private static function WriteSyncMarker(string $relativePath, string $localFile): void
    {
        if(!is_file($localFile))
        {
            return;
        }

        $marker = self::SyncMarkerFile($relativePath);
        $directory = dirname($marker);
        if((is_dir($directory) || @mkdir($directory, 0775, true)) && is_writable($directory))
        {
            if(@file_put_contents($marker, self::LocalFileVersion($localFile), LOCK_EX) !== false)
            {
                return;
            }
        }
        error_log('ShopXO OSS sync marker is not writable: '.$marker);
    }

    private static function DeleteSyncMarker(string $relativePath): void
    {
        $marker = self::SyncMarkerFile($relativePath);
        if(is_file($marker))
        {
            @unlink($marker);
        }
    }

    private static function SyncMarkerFile(string $relativePath): string
    {
        $runtimeRoot = defined('ROOT') ? ROOT.'runtime' : dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'runtime';
        return rtrim($runtimeRoot, '/\\').DIRECTORY_SEPARATOR.'oss-sync'.DIRECTORY_SEPARATOR.sha1(self::RelativePath($relativePath)).'.txt';
    }

    private static function LocalFileVersion(string $localFile): string
    {
        return (string)filemtime($localFile).':'.(string)filesize($localFile);
    }
}
