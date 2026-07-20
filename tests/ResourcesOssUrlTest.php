<?php

namespace app\service {
    class SystemBaseService
    {
        public static function AttachmentHost(): string
        {
            return 'https://shop.denlaw.cn';
        }
    }
}

namespace {
    $testRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'shopxo-resources-oss-'.bin2hex(random_bytes(4)).DIRECTORY_SEPARATOR;
    define('ROOT', $testRoot);
    ini_set('error_log', ROOT.'test-error.log');

    function MyConfig($key)
    {
        $config = [
            'oss.enabled' => true,
            'oss.public_url' => 'https://denshop.oss-cn-beijing.aliyuncs.com',
        ];
        return $config[$key] ?? null;
    }

    require dirname(__DIR__).'/vendor/autoload.php';

    class FailingResourcesOssStorage extends \app\storage\OssStorage
    {
        public bool $failUploads = false;

        public function __construct()
        {
        }

        public function put(string $localFile, string $objectPath): string
        {
            if($this->failUploads) throw new \RuntimeException('simulated outage');
            return $this->publicUrl($objectPath);
        }

        public function delete(string $objectPath): void
        {
        }

        public function publicUrl(string $objectPath): string
        {
            return 'https://denshop.oss-cn-beijing.aliyuncs.com/'.ltrim($objectPath, '/');
        }
    }

    function assertResourcesSame($expected, $actual, string $message): void
    {
        if($expected !== $actual)
        {
            throw new \RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
        }
    }

    function removeResourcesTestDirectory(string $directory): void
    {
        if(!is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach($iterator as $item)
        {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    $storage = new FailingResourcesOssStorage();
    $reflection = new \ReflectionClass(\app\service\OssStorageService::class);
    $property = $reflection->getProperty('storage');
    $property->setAccessible(true);
    $property->setValue(null, $storage);

    try
    {
        $relativePath = '/static/upload/images/goods/test.png';
        assertResourcesSame(
            'https://denshop.oss-cn-beijing.aliyuncs.com/static/upload/images/goods/test.png',
            \app\service\ResourcesService::AttachmentPathViewHandle($relativePath),
            'API attachment paths should be converted to absolute OSS URLs'
        );

        $fallbackPath = '/static/upload/images/user_avatar/fallback.png';
        $localFile = ROOT.'public'.str_replace('/', DIRECTORY_SEPARATOR, $fallbackPath);
        mkdir(dirname($localFile), 0775, true);
        file_put_contents($localFile, 'avatar');
        $storage->failUploads = true;
        assertResourcesSame(
            'https://shop.denlaw.cn/static/upload/images/user_avatar/fallback.png',
            \app\service\ResourcesService::AttachmentPathViewHandle($fallbackPath),
            'Mini-program API consumers should receive an absolute HTTPS fallback URL when OSS is unavailable'
        );

        echo "Resources OSS URL tests passed\n";
    } finally {
        removeResourcesTestDirectory(ROOT);
    }
}
