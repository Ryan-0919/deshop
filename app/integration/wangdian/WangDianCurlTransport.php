<?php
namespace app\integration\wangdian;

final class WangDianCurlTransport implements WangDianTransportInterface
{
    private int $connectTimeout;
    private int $timeout;

    public function __construct(array $config = [])
    {
        $this->connectTimeout = max(1, (int)($config['connect_timeout'] ?? 5));
        $this->timeout = max($this->connectTimeout, (int)($config['timeout'] ?? 30));
    }

    public function post(string $url, array $form): string
    {
        if(!function_exists('curl_init'))
        {
            throw new \RuntimeException('The PHP cURL extension is required for Wangdiantong integration.');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if($response === false)
        {
            throw new \RuntimeException('Wangdiantong request failed: '.$error);
        }
        if($status < 200 || $status >= 300)
        {
            throw new \RuntimeException('Wangdiantong request failed with HTTP '.$status.'.');
        }
        return (string)$response;
    }
}
