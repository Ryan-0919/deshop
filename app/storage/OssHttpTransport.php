<?php
namespace app\storage;

class OssHttpTransport implements OssTransportInterface
{
    private string $endpoint;
    private string $accessKeyId;
    private string $accessKeySecret;
    private int $connectTimeout;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->endpoint = rtrim((string)($config['endpoint'] ?? ''), '/');
        $this->accessKeyId = trim((string)($config['access_key_id'] ?? ''));
        $this->accessKeySecret = (string)($config['access_key_secret'] ?? '');
        $this->connectTimeout = max(1, (int)($config['connect_timeout'] ?? 5));
        $this->timeout = max($this->connectTimeout, (int)($config['timeout'] ?? 60));

        if($this->endpoint === '' || $this->accessKeyId === '' || $this->accessKeySecret === '')
        {
            throw new \InvalidArgumentException('OSS endpoint and RAM AccessKey credentials are required.');
        }
        if(!preg_match('#^https?://#i', $this->endpoint))
        {
            $this->endpoint = 'https://'.$this->endpoint;
        }
    }

    public function put(string $bucket, string $objectKey, string $localFile, string $contentType): void
    {
        $handle = fopen($localFile, 'rb');
        if($handle === false)
        {
            throw new \RuntimeException('Unable to open the local attachment for OSS upload.');
        }

        try
        {
            $this->request('PUT', $bucket, $objectKey, $contentType, $handle, filesize($localFile));
        } finally {
            fclose($handle);
        }
    }

    public function delete(string $bucket, string $objectKey): void
    {
        $this->request('DELETE', $bucket, $objectKey);
    }

    private function request(string $method, string $bucket, string $objectKey, string $contentType = '', $stream = null, int $contentLength = 0): void
    {
        if(!function_exists('curl_init'))
        {
            throw new \RuntimeException('The PHP cURL extension is required for OSS storage.');
        }

        $date = gmdate('D, d M Y H:i:s').' GMT';
        $canonicalResource = '/'.$bucket.'/'.$objectKey;
        $stringToSign = $method."\n\n".$contentType."\n".$date."\n".$canonicalResource;
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret, true));
        $headers = [
            'Authorization: OSS '.$this->accessKeyId.':'.$signature,
            'Date: '.$date,
            'Content-Type: '.$contentType,
            'Expect:',
        ];

        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        $endpoint = parse_url($this->endpoint);
        $scheme = $endpoint['scheme'] ?? 'https';
        $host = $endpoint['host'] ?? '';
        $port = isset($endpoint['port']) ? ':'.$endpoint['port'] : '';
        $url = $scheme.'://'.$bucket.'.'.$host.$port.'/'.$encodedKey;

        $curl = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if(is_resource($stream))
        {
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $stream;
            $options[CURLOPT_INFILESIZE] = $contentLength;
        }
        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if($response === false)
        {
            throw new \RuntimeException('OSS request failed: '.$error);
        }
        if($status === 404)
        {
            throw new OssObjectNotFoundException('The OSS object does not exist.');
        }
        if($status < 200 || $status >= 300)
        {
            $message = trim(strip_tags((string)$response));
            throw new \RuntimeException('OSS request failed with HTTP '.$status.($message === '' ? '' : ': '.$message));
        }
    }
}

