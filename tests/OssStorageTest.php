<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\storage\OssObjectNotFoundException;
use app\storage\OssStorage;
use app\storage\OssTransportInterface;

final class FakeOssTransport implements OssTransportInterface
{
    public array $puts = [];
    public array $deletes = [];
    public bool $throwNotFoundOnDelete = false;

    public function put(string $bucket, string $objectKey, string $localFile, string $contentType): void
    {
        $this->puts[] = compact('bucket', 'objectKey', 'localFile', 'contentType');
    }

    public function delete(string $bucket, string $objectKey): void
    {
        $this->deletes[] = compact('bucket', 'objectKey');
        if($this->throwNotFoundOnDelete)
        {
            throw new OssObjectNotFoundException('missing');
        }
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $message): void
{
    try
    {
        $callback();
    } catch(InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException($message);
}

function newStorage(FakeOssTransport $transport): OssStorage
{
    return new OssStorage([
        'bucket' => 'denshop',
        'public_url' => 'https://denshop.oss-cn-beijing.aliyuncs.com',
    ], $transport);
}

$tempFile = tempnam(sys_get_temp_dir(), 'oss-test-');
file_put_contents($tempFile, 'image-content');

try
{
    $transport = new FakeOssTransport();
    $storage = newStorage($transport);
    $url = $storage->put($tempFile, '/static/upload/images/goods/test.jpg');

    assertSameValue('https://denshop.oss-cn-beijing.aliyuncs.com/static/upload/images/goods/test.jpg', $url, 'put() should return the public object URL');
    assertSameValue('denshop', $transport->puts[0]['bucket'], 'put() should target the configured bucket');
    assertSameValue('static/upload/images/goods/test.jpg', $transport->puts[0]['objectKey'], 'put() should preserve the stable ShopXO attachment path as the object key');
    assertSameValue($tempFile, $transport->puts[0]['localFile'], 'put() should upload the local file');

    $transport = new FakeOssTransport();
    $storage = newStorage($transport);
    $storage->delete('https://denshop.oss-cn-beijing.aliyuncs.com/static/upload/images/goods/test.jpg');
    assertSameValue('static/upload/images/goods/test.jpg', $transport->deletes[0]['objectKey'], 'delete() should accept a public OSS URL');

    $transport->throwNotFoundOnDelete = true;
    $storage->delete('/static/upload/images/goods/already-gone.jpg');
    assertSameValue('static/upload/images/goods/already-gone.jpg', $transport->deletes[1]['objectKey'], 'delete() should tolerate an already missing object');

    assertSameValue(
        'https://denshop.oss-cn-beijing.aliyuncs.com/static/upload/images/a%20b.jpg',
        $storage->publicUrl('/static/upload/images/a b.jpg'),
        'publicUrl() should build a safe public URL'
    );

    assertThrows(
        fn() => $storage->objectKey('/static/upload/../../private-key.pem'),
        'Parent directory segments must not be accepted as OSS object keys'
    );

    echo "OssStorage tests passed\n";
} finally {
    @unlink($tempFile);
}
