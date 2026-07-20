<?php

$testRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'shopxo-oss-service-'.bin2hex(random_bytes(4)).DIRECTORY_SEPARATOR;
define('ROOT', $testRoot);

function MyConfig($key)
{
    $config = [
        'oss.enabled' => true,
        'oss.public_url' => 'https://denshop.oss-cn-beijing.aliyuncs.com',
    ];
    return $config[$key] ?? null;
}

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\OssStorageService;
use app\storage\OssStorage;

final class FakeServiceOssStorage extends OssStorage
{
    public array $puts = [];
    public array $deletes = [];
    public bool $failUploads = false;

    public function __construct()
    {
    }

    public function put(string $localFile, string $objectPath): string
    {
        if($this->failUploads)
        {
            throw new RuntimeException('simulated outage');
        }
        $this->puts[] = compact('localFile', 'objectPath');
        return $this->publicUrl($objectPath);
    }

    public function delete(string $objectPath): void
    {
        $this->deletes[] = $objectPath;
    }

    public function publicUrl(string $objectPath): string
    {
        return 'https://denshop.oss-cn-beijing.aliyuncs.com/'.ltrim($objectPath, '/');
    }
}

function assertServiceSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

function removeTestDirectory(string $directory): void
{
    if(!is_dir($directory)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach($iterator as $item)
    {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

$relativePath = '/static/upload/images/user_avatar/test.png';
$localFile = ROOT.'public'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
mkdir(dirname($localFile), 0775, true);
file_put_contents($localFile, 'avatar-v1');

$storage = new FakeServiceOssStorage();
$reflection = new ReflectionClass(OssStorageService::class);
$property = $reflection->getProperty('storage');
$property->setAccessible(true);
$property->setValue(null, $storage);

try
{
    $url = OssStorageService::PublicUrl($relativePath);
    assertServiceSame('https://denshop.oss-cn-beijing.aliyuncs.com/static/upload/images/user_avatar/test.png', $url, 'A directly generated local upload should be mirrored before its OSS URL is returned');
    assertServiceSame(1, count($storage->puts), 'The first view should mirror the local file');

    OssStorageService::PublicUrl($relativePath);
    assertServiceSame(1, count($storage->puts), 'An unchanged local file should not be uploaded repeatedly');

    file_put_contents($localFile, 'avatar-v2-with-new-size');
    clearstatcache(true, $localFile);
    OssStorageService::PublicUrl($relativePath);
    assertServiceSame(2, count($storage->puts), 'A changed local file should be mirrored again');

    OssStorageService::Delete($relativePath);
    assertServiceSame([$relativePath], $storage->deletes, 'Deleting an attachment should also delete its OSS object');

    $storage->failUploads = true;
    file_put_contents($localFile, 'avatar-v3-with-another-size-change');
    clearstatcache(true, $localFile);
    assertServiceSame($relativePath, OssStorageService::PublicUrl($relativePath), 'An OSS outage should fall back to the local attachment URL');

    echo "OssStorageService tests passed\n";
} finally {
    removeTestDirectory(ROOT);
}

