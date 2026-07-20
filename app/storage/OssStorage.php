<?php
namespace app\storage;

class OssStorage
{
    private string $bucket;
    private string $publicUrl;
    private OssTransportInterface $transport;

    public function __construct(array $config, OssTransportInterface $transport)
    {
        $this->bucket = trim((string)($config['bucket'] ?? ''));
        $this->publicUrl = rtrim((string)($config['public_url'] ?? ''), '/');
        $this->transport = $transport;

        if($this->bucket === '' || $this->publicUrl === '')
        {
            throw new \InvalidArgumentException('OSS bucket and public URL are required.');
        }
    }

    public function put(string $localFile, string $objectPath): string
    {
        if(!is_file($localFile) || !is_readable($localFile))
        {
            throw new \RuntimeException('The local attachment file is missing or unreadable.');
        }

        $objectKey = $this->objectKey($objectPath);
        $contentType = function_exists('mime_content_type') ? (string)mime_content_type($localFile) : '';
        if($contentType === '')
        {
            $contentType = 'application/octet-stream';
        }

        $this->transport->put($this->bucket, $objectKey, $localFile, $contentType);
        return $this->publicUrl($objectKey);
    }

    public function delete(string $objectPath): void
    {
        $objectKey = $this->objectKey($objectPath);
        if($objectKey === '')
        {
            return;
        }

        try
        {
            $this->transport->delete($this->bucket, $objectKey);
        } catch(OssObjectNotFoundException $e) {
            // Deleting an already missing object is idempotent.
        }
    }

    public function publicUrl(string $objectPath): string
    {
        $objectKey = $this->objectKey($objectPath);
        $segments = array_map('rawurlencode', explode('/', $objectKey));
        return $this->publicUrl.'/'.implode('/', $segments);
    }

    public function objectKey(string $objectPath): string
    {
        return self::normalizeObjectPath($objectPath);
    }

    public static function normalizeObjectPath(string $objectPath): string
    {
        $objectPath = trim($objectPath);
        if(preg_match('#^https?://#i', $objectPath))
        {
            $objectPath = (string)parse_url($objectPath, PHP_URL_PATH);
        }
        $objectPath = str_replace('\\', '/', rawurldecode($objectPath));
        if(preg_match('/[\x00-\x1F\x7F]/', $objectPath))
        {
            throw new \InvalidArgumentException('The OSS object path contains control characters.');
        }

        $segments = [];
        foreach(explode('/', $objectPath) as $segment)
        {
            if($segment === '' || $segment === '.') continue;
            if($segment === '..')
            {
                throw new \InvalidArgumentException('The OSS object path cannot contain parent directory segments.');
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }
}
