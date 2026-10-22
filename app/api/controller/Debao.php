<?php

namespace app\api\controller;

use app\BaseController;
use app\integration\debao\DebaoBindingService;
use app\integration\debao\DebaoHmac;
use app\integration\debao\DebaoAddressService;
use app\integration\debao\DebaoSessionService;
use app\integration\debao\DebaoOrderService;
use app\integration\debao\DebaoCatalogService;

final class Debao extends BaseController
{
    public function Categories()
    {
        return $this->run('GET', fn(array $body, string $userId) => DebaoCatalogService::categories((array)$this->request->get(), (array)config('debao')), false);
    }

    public function Products()
    {
        return $this->run('GET', fn(array $body, string $userId) => DebaoCatalogService::products((array)$this->request->get(), (array)config('debao')), false);
    }

    public function Product(string $product_id = '')
    {
        return $this->run('GET', fn(array $body, string $userId) => DebaoCatalogService::product($product_id, (array)config('debao')), false);
    }

    public function ProductSkus(string $product_id = '')
    {
        return $this->run('GET', fn(array $body, string $userId) => DebaoCatalogService::skus($product_id, (array)$this->request->get()), false);
    }

    public function Challenges()
    {
        return $this->run('POST', function(array $body, string $userId) {
            if(trim((string)($body['debao_user_id'] ?? '')) !== $userId) return ['code'=>-1,'status'=>403,'error'=>'USER_CONTEXT_MISMATCH'];
            return DebaoBindingService::createChallenge($userId, (string)($body['method'] ?? ''), (array)config('debao'), time());
        });
    }

    public function Challenge(string $challenge_id = '')
    {
        return $this->run('GET', function(array $body, string $userId) use ($challenge_id) {
            return DebaoBindingService::getChallenge($challenge_id, $userId, time());
        });
    }

    public function Confirm(string $challenge_id = '')
    {
        return $this->run('POST', function(array $body, string $userId) use ($challenge_id) {
            return DebaoBindingService::confirm($challenge_id, $userId, $body, (array)config('debao'), time());
        });
    }

    public function Status()
    {
        return $this->run('GET', fn(array $body, string $userId) => DebaoBindingService::status($userId));
    }

    public function Unbind()
    {
        return $this->run('DELETE', fn(array $body, string $userId) => DebaoBindingService::unbind($userId, time()));
    }

    public function Addresses()
    {
        return $this->run('GET', function(array $body, string $userId) {
            $cursor = $this->request->param('cursor');
            $limit = (int)$this->request->param('limit', 20);
            return DebaoAddressService::list($userId, $cursor === null ? null : (string)$cursor, $limit);
        });
    }

    public function AddressesCreate()
    {
        return $this->run('POST', fn(array $body, string $userId) => DebaoAddressService::create($userId, $body));
    }

    public function AddressUpdate(string $address_id = '')
    {
        return $this->run('PATCH', fn(array $body, string $userId) => DebaoAddressService::update($userId, $address_id, $body));
    }

    public function AddressDelete(string $address_id = '')
    {
        return $this->run('DELETE', fn(array $body, string $userId) => DebaoAddressService::delete($userId, $address_id));
    }

    public function AddressDefault(string $address_id = '')
    {
        return $this->run('POST', fn(array $body, string $userId) => DebaoAddressService::setDefault($userId, $address_id));
    }

    public function SsoSession()
    {
        return $this->run('POST', fn(array $body, string $userId) => DebaoSessionService::sso($userId, $body, time(), (array)config('debao')));
    }

    public function CheckoutSession()
    {
        return $this->run('POST', fn(array $body, string $userId) => DebaoSessionService::checkout($userId, $body, time(), (array)config('debao')));
    }

    public function Session(string $session_id = '')
    {
        return $this->run('GET', fn(array $body, string $userId) => DebaoSessionService::get($userId, $session_id, time()));
    }

    public function OrderPay(string $order_id = '')
    {
        return $this->run('POST', fn(array $body, string $userId) => DebaoSessionService::payment($userId, $order_id, time(), (array)config('debao')));
    }
    public function Orders(){return $this->run('GET',fn(array $b,string $u)=>DebaoOrderService::list($u,$this->request->param('cursor'),(int)$this->request->param('limit',20)));}
    public function Order(string $order_id=''){return $this->run('GET',fn(array $b,string $u)=>DebaoOrderService::detail($u,$order_id));}
    public function OrderLogistics(string $order_id=''){return $this->run('GET',fn(array $b,string $u)=>DebaoOrderService::logistics($u,$order_id));}
    public function Webhook(){return $this->run('POST',fn(array $b,string $u)=>DebaoOrderService::webhook($b,time()));}

    private function run(string $method, callable $handler, bool $requireUser = true)
    {
        $config = (array)config('debao');
        if(empty($config['enabled'])) return $this->jsonError('DEBAO_DISABLED', 503);
        $headers = [];
        foreach($this->request->header() as $key=>$value) $headers[strtolower((string)$key)] = (string)$value;
        $bodyText = (string)$this->request->getContent();
        $queryParams = (array)$this->request->get();
        $query = self::canonicalQuery($queryParams);
        $path = '/'.ltrim((string)$this->request->pathinfo(), '/');
        $auth = DebaoHmac::verify($headers, $method, $path, $query, $bodyText, $config, time());
        if((int)($auth['code'] ?? -1) !== 0) return $this->jsonError((string)$auth['error'], 401);
        $userId = trim((string)($headers['x-debao-user-id'] ?? ''));
        if($requireUser && $userId === '') return $this->jsonError('USER_CONTEXT_REQUIRED', 401);
        $body = [];
        if($bodyText !== '')
        {
            $body = json_decode($bodyText, true);
            if(!is_array($body)) return $this->jsonError('INVALID_JSON', 400);
        }
        $result = $handler($body, $userId);
        if((int)($result['status'] ?? 200) === 204) return response('', 204);
        if((int)($result['code'] ?? -1) !== 0) return $this->jsonError((string)$result['error'], (int)($result['status'] ?? 400));
        return json(['data'=>$result['data'] ?? []], (int)($result['status'] ?? 200));
    }

    private static function canonicalQuery(array $query): string
    {
        ksort($query, SORT_STRING);
        foreach($query as &$value) if(is_array($value)) self::sortQueryValue($value);
        unset($value);
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function sortQueryValue(array &$value): void
    {
        ksort($value, SORT_STRING);
        foreach($value as &$item) if(is_array($item)) self::sortQueryValue($item);
        unset($item);
    }

    private function jsonError(string $code, int $status)
    {
        return json(['error'=>['code'=>$code,'message'=>$code,'request_id'=>uniqid('req_', true)]], $status);
    }
}
