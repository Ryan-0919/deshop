<?php
namespace app\storage;

interface OssTransportInterface
{
    public function put(string $bucket, string $objectKey, string $localFile, string $contentType): void;

    public function delete(string $bucket, string $objectKey): void;
}

