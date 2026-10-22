<?php

namespace app\integration\debao;

use think\facade\Db;

final class DebaoSessionService
{
    public static function sso(string $debaoUserId, array $body, int $now, array $config): array
    {
        if(trim((string)($body['debao_user_id'] ?? '')) !== $debaoUserId || filter_var($body['redirect_uri'] ?? '', FILTER_VALIDATE_URL) === false) return self::error('INVALID_REQUEST', 400);
        return self::create($debaoUserId, 'sso', ['redirect_uri'=>$body['redirect_uri'], 'state'=>(string)($body['state'] ?? '')], $now, (int)($config['session_ttl'] ?? 300));
    }

    public static function checkout(string $debaoUserId, array $body, int $now, array $config): array
    {
        $userId = self::boundUser($debaoUserId);
        if($userId === null) return self::error('BINDING_REQUIRED', 409);
        if(empty($body['items']) || !is_array($body['items'])) return self::error('INVALID_REQUEST', 400);
        $items = []; $totalCents = 0;
        foreach($body['items'] as $item) {
            $sku = (string)($item['sku_id'] ?? ''); $qty = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if($sku === '' || $qty === false) return self::error('INVALID_REQUEST', 400);
            $row = Db::name('GoodsSpecBase')->where(['id'=>(int)$sku])->field('id,goods_id,price,inventory')->find();
            if(empty($row)) return self::error('SKU_NOT_FOUND', 404);
            if((int)$row['inventory'] < $qty) return self::error('OUT_OF_STOCK', 409);
            $unit = (int)round(((float)$row['price']) * 100); $line = $unit * $qty; $totalCents += $line;
            $items[] = ['sku_id'=>$sku, 'quantity'=>$qty, 'unit_price_cents'=>$unit, 'line_total_cents'=>$line];
        }
        return self::create($debaoUserId, 'checkout', ['deshop_user_id'=>$userId, 'items'=>$items, 'total_cents'=>$totalCents, 'address_id'=>(string)($body['address_id'] ?? '')], $now, (int)($config['session_ttl'] ?? 300));
    }

    public static function get(string $debaoUserId, string $sessionId, int $now): array
    {
        $row = Db::name('DebaoSession')->where(['session_id'=>$sessionId, 'debao_user_id'=>$debaoUserId])->find();
        if(empty($row)) return self::error('NOT_FOUND', 404);
        if($row['status'] === 'pending' && (int)$row['expires_at'] <= $now) { Db::name('DebaoSession')->where(['id'=>$row['id']])->update(['status'=>'expired','upd_time'=>$now]); $row['status']='expired'; }
        $data = ['session_id'=>$row['session_id'], 'status'=>$row['status'], 'expires_at'=>gmdate('c',(int)$row['expires_at']), 'one_time'=>true];
        if($row['type'] === 'sso') { $payload=json_decode((string)$row['payload'], true) ?: []; $data['redirect_uri']=$payload['redirect_uri'] ?? ''; }
        if($row['type'] === 'checkout' && $row['status'] === 'pending') { $payload=json_decode((string)$row['payload'], true) ?: []; $data['total_cents']=(int)($payload['total_cents'] ?? 0); $data['items']=$payload['items'] ?? []; }
        return ['code'=>0,'status'=>200,'data'=>$data];
    }

    public static function payment(string $debaoUserId, string $orderId, int $now, array $config): array
    {
        $userId = self::boundUser($debaoUserId); if($userId === null) return self::error('BINDING_REQUIRED', 409);
        $id = ctype_digit($orderId) ? (int)$orderId : 0;
        $order = $id ? Db::name('Order')->where(['id'=>$id, 'user_id'=>$userId])->field('id,order_no,status,pay_status,total_price,pay_price')->find() : null;
        if(empty($order)) return self::error('NOT_FOUND', 404);
        if((int)$order['pay_status'] !== 0 || in_array((int)$order['status'], [5,6], true)) return self::error('ORDER_NOT_PAYABLE', 409);
        if(empty($config['payment_provider'])) return self::error('PAYMENT_PROVIDER_NOT_CONFIGURED', 503);
        return ['code'=>0,'status'=>200,'data'=>['order_id'=>(string)$order['id'], 'amount_cents'=>(int)round(((float)$order['total_price'])*100), 'payment_provider'=>(string)$config['payment_provider'], 'payment_intent_id'=>'dpi_'.bin2hex(random_bytes(16))]];
    }

    public static function consume(string $debaoUserId, string $sessionId, int $now): array
    {
        $row = Db::name('DebaoSession')->where(['session_id'=>$sessionId, 'debao_user_id'=>$debaoUserId])->find();
        if(empty($row)) return self::error('NOT_FOUND', 404);
        if($row['status'] !== 'pending' || (int)$row['expires_at'] <= $now) return self::error($row['status'] === 'pending' ? 'SESSION_EXPIRED' : 'SESSION_ALREADY_CONSUMED', 409);
        Db::name('DebaoSession')->where(['id'=>$row['id'], 'status'=>'pending'])->update(['status'=>'consumed','consumed_at'=>$now,'upd_time'=>$now]);
        return ['code'=>0,'status'=>200,'data'=>['session_id'=>$sessionId,'status'=>'consumed','expires_at'=>gmdate('c',(int)$row['expires_at']),'one_time'=>true]];
    }

    private static function create(string $user, string $type, array $payload, int $now, int $ttl): array
    {
        $id = 'dss_'.bin2hex(random_bytes(16)); $expires = $now + max(60, min(900, $ttl));
        Db::name('DebaoSession')->insert(['session_id'=>$id,'debao_user_id'=>$user,'type'=>$type,'status'=>'pending','payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'expires_at'=>$expires,'add_time'=>$now,'upd_time'=>$now]);
        $data=['session_id'=>$id,'status'=>'pending','expires_at'=>gmdate('c',$expires),'one_time'=>true]; if($type==='sso') $data['redirect_uri']=$payload['redirect_uri']; else { $data['total_cents']=$payload['total_cents']; $data['items']=$payload['items']; }
        return ['code'=>0,'status'=>201,'data'=>$data];
    }
    private static function boundUser(string $id): ?int { $row=Db::name('DebaoBinding')->where(['debao_user_id'=>$id,'status'=>'active'])->find(); return empty($row) ? null : (int)$row['deshop_user_id']; }
    private static function error(string $error, int $status): array { return ['code'=>-1,'status'=>$status,'error'=>$error]; }
}
