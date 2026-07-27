<?php
namespace app\integration\wangdian;

final class WangDianClient implements WangDianClientInterface
{
    private string $baseUrl;
    private string $sid;
    private string $appKey;
    private string $appSecret;
    private WangDianTransportInterface $transport;
    private $clock;

    public function __construct(array $config, ?WangDianTransportInterface $transport = null, ?callable $clock = null)
    {
        $this->baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        $this->sid = trim((string)($config['sid'] ?? ''));
        $this->appKey = trim((string)($config['appkey'] ?? ''));
        $this->appSecret = (string)($config['appsecret'] ?? '');
        $this->transport = $transport ?? new WangDianCurlTransport($config);
        $this->clock = $clock ?? static fn(): int => time();

        if(!str_starts_with(strtolower($this->baseUrl), 'https://'))
        {
            throw new \InvalidArgumentException('Wangdiantong base URL must use HTTPS.');
        }
        if($this->sid === '' || $this->appKey === '' || $this->appSecret === '')
        {
            throw new \InvalidArgumentException('Wangdiantong sid, appkey and appsecret are required.');
        }
    }

    public function request(string $endpoint, array $businessParams = []): array
    {
        if(!preg_match('/^[a-z0-9_]+\.php$/i', $endpoint))
        {
            throw new \InvalidArgumentException('Invalid Wangdiantong API endpoint.');
        }

        $params = $businessParams;
        $params['sid'] = $this->sid;
        $params['appkey'] = $this->appKey;
        $params['timestamp'] = (string)call_user_func($this->clock);
        foreach($params as $key => $value)
        {
            if(is_array($value) || is_object($value))
            {
                throw new \InvalidArgumentException('Wangdiantong request parameter '.$key.' must be encoded before sending.');
            }
            $params[$key] = $value === null ? '' : (string)$value;
        }
        $params['sign'] = WangDianSigner::sign($params, $this->appSecret);

        $rawResponse = $this->transport->post($this->baseUrl.'/'.$endpoint, $params);
        try {
            $response = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch(\JsonException $e) {
            throw new \RuntimeException('Wangdiantong returned invalid JSON.', 0, $e);
        }

        if(!is_array($response))
        {
            throw new \RuntimeException('Wangdiantong returned an invalid response.');
        }
        $code = $response['code'] ?? null;
        $isSuccess = (is_int($code) && $code === 0) || (is_string($code) && $code === '0');
        if(!$isSuccess)
        {
            $message = trim((string)($response['message'] ?? ''));
            $isValidErrorCode = (is_int($code) && $code !== 0)
                || (is_string($code) && preg_match('/^-?[1-9]\d*$/D', $code) === 1);
            throw new WangDianApiException(
                !$isValidErrorCode
                    ? 'Wangdiantong returned an invalid response code.'
                    : ($message === '' ? 'Wangdiantong request was rejected.' : $message),
                $response
            );
        }
        return $response;
    }
}
