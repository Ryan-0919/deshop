<?php

namespace app\integration\debao;

use think\facade\Db;

/** Address adapter for the DeBao contract. Never accepts a caller supplied ShopXO user id. */
final class DebaoAddressService
{
    public static function list(string $debaoUserId, ?string $cursor, int $limit): array
    {
        $userId = self::boundUser($debaoUserId);
        if($userId === null) return self::error('BINDING_REQUIRED', 409);
        $limit = max(1, min(100, $limit));
        $query = Db::name('UserAddress')->where('user_id', $userId)->order('id asc')->limit($limit + 1);
        if($cursor !== null && $cursor !== '') { $decoded = base64_decode($cursor, true); if($decoded === false || !ctype_digit($decoded)) return self::error('INVALID_CURSOR', 400); $query->where('id', '>', (int)$decoded); }
        $rows = $query->select()->toArray();
        $hasMore = count($rows) > $limit;
        if($hasMore) array_pop($rows);
        $next = $hasMore && !empty($rows) ? base64_encode((string)end($rows)['id']) : null;
        return self::ok(['items'=>array_map([self::class, 'publicAddress'], $rows), 'next_cursor'=>$next, 'has_more'=>$hasMore]);
    }

    public static function create(string $debaoUserId, array $input): array
    {
        $userId = self::boundUser($debaoUserId);
        if($userId === null) return self::error('BINDING_REQUIRED', 409);
        $data = self::writeData($input, false);
        if(isset($data['error'])) return $data;
        $data['user_id'] = $userId;
        $data['add_time'] = time();
        Db::startTrans();
        if(!empty($data['is_default'])) Db::name('UserAddress')->where(['user_id'=>$userId])->update(['is_default'=>0]);
        $id = Db::name('UserAddress')->insertGetId($data);
        if(!$id) { Db::rollback(); return self::error('WRITE_FAILED', 500); }
        Db::commit();
        return self::row($userId, (int)$id, 201);
    }

    public static function update(string $debaoUserId, string $addressId, array $input): array
    {
        $userId = self::boundUser($debaoUserId);
        if($userId === null) return self::error('BINDING_REQUIRED', 409);
        $id = self::id($addressId);
        if($id === null) return self::error('NOT_FOUND', 404);
        $data = self::writeData($input, true);
        if(isset($data['error'])) return $data;
        $existing = Db::name('UserAddress')->where(['id'=>$id, 'user_id'=>$userId])->find();
        if(empty($existing)) return self::error('NOT_FOUND', 404);
        Db::startTrans();
        if(!empty($data['is_default'])) Db::name('UserAddress')->where(['user_id'=>$userId])->update(['is_default'=>0]);
        $data['upd_time'] = time();
        if(Db::name('UserAddress')->where(['id'=>$id, 'user_id'=>$userId])->update($data) === false) { Db::rollback(); return self::error('WRITE_FAILED', 500); }
        Db::commit();
        return self::row($userId, $id, 200);
    }

    public static function delete(string $debaoUserId, string $addressId): array
    {
        $userId = self::boundUser($debaoUserId);
        if($userId === null) return self::error('BINDING_REQUIRED', 409);
        $id = self::id($addressId);
        if($id === null || !Db::name('UserAddress')->where(['id'=>$id, 'user_id'=>$userId])->find()) return self::error('NOT_FOUND', 404);
        if(!Db::name('UserAddress')->where(['id'=>$id, 'user_id'=>$userId])->delete()) return self::error('WRITE_FAILED', 500);
        return ['code'=>0, 'status'=>204];
    }

    public static function setDefault(string $debaoUserId, string $addressId): array
    {
        $userId = self::boundUser($debaoUserId);
        if($userId === null) return self::error('BINDING_REQUIRED', 409);
        $id = self::id($addressId);
        if($id === null || !Db::name('UserAddress')->where(['id'=>$id, 'user_id'=>$userId])->find()) return self::error('NOT_FOUND', 404);
        Db::startTrans();
        Db::name('UserAddress')->where(['user_id'=>$userId])->update(['is_default'=>0]);
        if(!Db::name('UserAddress')->where(['id'=>$id, 'user_id'=>$userId])->update(['is_default'=>1])) { Db::rollback(); return self::error('WRITE_FAILED', 500); }
        Db::commit();
        return self::row($userId, $id, 200);
    }

    private static function boundUser(string $debaoUserId): ?int
    {
        $row = Db::name('DebaoBinding')->where(['debao_user_id'=>$debaoUserId, 'status'=>'active'])->find();
        return empty($row) ? null : (int)$row['deshop_user_id'];
    }

    private static function row(int $userId, int $id, int $status): array
    {
        $row = Db::name('UserAddress')->where(['id'=>$id, 'user_id'=>$userId])->find();
        return empty($row) ? self::error('NOT_FOUND', 404) : self::ok(self::publicAddress($row), $status);
    }

    private static function writeData(array $input, bool $partial): array
    {
        $allowed = ['name','phone','province','city','district','detail','is_default'];
        foreach(array_keys($input) as $key) if(!in_array($key, $allowed, true)) return self::error('INVALID_REQUEST', 400);
        if(!$partial) foreach(['name','phone','province','city','district','detail'] as $key) if(!isset($input[$key]) || trim((string)$input[$key]) === '') return self::error('INVALID_REQUEST', 400);
        $data = [];
        foreach(['name','phone','detail'] as $key) if(array_key_exists($key, $input)) $data[$key === 'phone' ? 'tel' : ($key === 'detail' ? 'address' : $key)] = trim((string)$input[$key]);
        foreach(['province'=>1,'city'=>2,'district'=>3] as $key=>$level) if(array_key_exists($key, $input)) { $region = self::regionId($input[$key], $level, $data); if($region === null) return self::error('INVALID_REGION', 400); $data[$key === 'district' ? 'county' : $key] = $region; }
        if(array_key_exists('is_default', $input)) { if(!is_bool($input['is_default']) && !in_array($input['is_default'], [0,1,'0','1'], true)) return self::error('INVALID_REQUEST', 400); $data['is_default'] = (int)(bool)$input['is_default']; }
        return $data;
    }

    private static function regionId($value, int $level, array $data): ?int
    {
        $value = trim((string)$value); if($value === '') return null;
        if(ctype_digit($value)) { $id=(int)$value; return $id > 0 && Db::name('Region')->where(['id'=>$id,'level'=>$level])->value('id') ? $id : null; }
        $where = ['name'=>$value, 'level'=>$level];
        if($level > 1) { $parent = $level === 2 ? ($data['province'] ?? null) : ($data['city'] ?? null); if($parent) $where['pid'] = $parent; }
        $id = Db::name('Region')->where($where)->value('id'); return $id ? (int)$id : null;
    }

    private static function publicAddress(array $row): array
    { return ['address_id'=>(string)$row['id'], 'name'=>(string)$row['name'], 'phone'=>(string)$row['tel'], 'province'=>(string)$row['province'], 'city'=>(string)$row['city'], 'district'=>(string)$row['county'], 'detail'=>(string)$row['address'], 'is_default'=>(bool)$row['is_default']]; }
    private static function id(string $value): ?int { return ctype_digit($value) && (int)$value > 0 ? (int)$value : null; }
    private static function ok(array $data, int $status=200): array { return ['code'=>0,'status'=>$status,'data'=>$data]; }
    private static function error(string $error, int $status): array { return ['code'=>-1,'status'=>$status,'error'=>$error]; }
}
