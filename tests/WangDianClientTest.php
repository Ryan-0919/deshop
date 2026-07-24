<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianClient;
use app\integration\wangdian\WangDianApiException;
use app\integration\wangdian\WangDianTransportInterface;

function assertWangDianClientSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

final class FakeWangDianTransport implements WangDianTransportInterface
{
    public string $url = '';
    public array $form = [];
    public string $response = '{"code":0,"message":"success","new_count":1}';

    public function post(string $url, array $form): string
    {
        $this->url = $url;
        $this->form = $form;
        return $this->response;
    }
}

$transport = new FakeWangDianTransport();
$client = new WangDianClient([
    'base_url' => 'https://sandbox.wangdian.cn/openapi2',
    'sid' => 'test2',
    'appkey' => 'test2-xx',
    'appsecret' => '12345',
], $transport, static fn(): int => 1470042310);

$response = $client->request('trade_push.php', [
    'shop_no' => 'api_test',
    'trade_list' => '[]',
]);

assertWangDianClientSame('https://sandbox.wangdian.cn/openapi2/trade_push.php', $transport->url, 'The client must call the selected environment over HTTPS');
assertWangDianClientSame('test2', $transport->form['sid'] ?? null, 'The request must contain the seller account');
assertWangDianClientSame('test2-xx', $transport->form['appkey'] ?? null, 'The request must contain the application key');
assertWangDianClientSame('1470042310', $transport->form['timestamp'] ?? null, 'The request timestamp must be a ten digit Unix timestamp');
assertWangDianClientSame('addb1de167c3806456fb46da17aa90b9', $transport->form['sign'] ?? null, 'The request signature must include common and business parameters');
assertWangDianClientSame(false, array_key_exists('appsecret', $transport->form), 'The application secret must never be transmitted');
assertWangDianClientSame(1, $response['new_count'] ?? null, 'The client must decode a successful JSON response');

$transport->response = '{"code":1001,"message":"invalid shop"}';
try
{
    $client->request('trade_push.php', ['shop_no' => 'bad', 'trade_list' => '[]']);
    throw new RuntimeException('A Wangdiantong business error must raise an exception');
} catch(RuntimeException $e) {
    assertWangDianClientSame('invalid shop', $e->getMessage(), 'The client must expose the Wangdiantong error message');
    assertWangDianClientSame(true, $e instanceof WangDianApiException, 'A business error must retain its structured response');
    assertWangDianClientSame(1001, $e instanceof WangDianApiException ? $e->response()['code'] : null, 'The structured error response must retain its code');
}

echo "WangDianClient tests passed\n";
