<?php

namespace app\integration\debao;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class DebaoCatalogService
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public static function categories(array $query, array $config): array
    {
        $validated = self::listQuery($query, ['cursor', 'limit', 'parent_id']);
        if(isset($validated['error'])) return $validated;

        $parentId = null;
        if(array_key_exists('parent_id', $query))
        {
            $parentId = self::positiveId($query['parent_id'], true);
            if($parentId === null) return self::error('INVALID_PARENT_ID', 400);
            if($parentId > 0 && !Db::name('GoodsCategory')->where(['id'=>$parentId, 'is_enable'=>1])->value('id')) return self::error('NOT_FOUND', 404);
        }

        $builder = Db::name('GoodsCategory')->where('is_enable', 1)->order('id asc')->limit($validated['limit'] + 1);
        if($validated['cursor'] !== null) $builder->where('id', '>', $validated['cursor']);
        if($parentId !== null) $builder->where('pid', $parentId);
        $rows = $builder->field('id,pid,name,is_enable')->select()->toArray();
        return self::page($rows, $validated['limit'], static fn(array $row): array => [
            'id'=>(string)$row['id'],
            'parent_id'=>(int)$row['pid'] === 0 ? null : (string)$row['pid'],
            'name'=>(string)$row['name'],
            'active'=>(bool)$row['is_enable'],
        ]);
    }

    public static function products(array $query, array $config): array
    {
        $validated = self::listQuery($query, ['cursor', 'limit', 'category_id', 'updated_after']);
        if(isset($validated['error'])) return $validated;

        $categoryId = null;
        if(array_key_exists('category_id', $query))
        {
            $categoryId = self::positiveId($query['category_id']);
            if($categoryId === null) return self::error('INVALID_CATEGORY_ID', 400);
            if(!Db::name('GoodsCategory')->where(['id'=>$categoryId, 'is_enable'=>1])->value('id')) return self::error('NOT_FOUND', 404);
        }

        $updatedAfter = null;
        if(array_key_exists('updated_after', $query))
        {
            $updatedAfter = self::utcTimestamp($query['updated_after']);
            if($updatedAfter === null) return self::error('INVALID_UPDATED_AFTER', 400);
        }

        $builder = Db::name('Goods')->alias('g')->where(['g.is_shelves'=>1, 'g.is_delete_time'=>0])->order('g.id asc')->limit($validated['limit'] + 1);
        if($validated['cursor'] !== null) $builder->where('g.id', '>', $validated['cursor']);
        if($updatedAfter !== null)
        {
            $builder->where(function($where) use ($updatedAfter) {
                $where->where('g.add_time', '>', $updatedAfter)->whereOr('g.upd_time', '>', $updatedAfter);
            });
        }
        if($categoryId !== null)
        {
            $builder->whereIn('g.id', function($where) use ($categoryId) {
                $where->name('GoodsCategoryJoin')->where('category_id', $categoryId)->field('goods_id');
            });
        }
        $rows = $builder->field(self::productFields('g'))->select()->toArray();
        return self::productPage($rows, $validated['limit'], $config);
    }

    public static function product(string $productId, array $config): array
    {
        $id = self::positiveId($productId);
        if($id === null) return self::error('INVALID_PRODUCT_ID', 400);
        $row = Db::name('Goods')->where(['id'=>$id, 'is_shelves'=>1, 'is_delete_time'=>0])->field(self::productFields())->find();
        if(empty($row)) return self::error('NOT_FOUND', 404);
        $related = self::related([$id]);
        return self::ok(self::publicProduct($row, $related, $config));
    }

    public static function skus(string $productId, array $query): array
    {
        $id = self::positiveId($productId);
        if($id === null) return self::error('INVALID_PRODUCT_ID', 400);
        if(!Db::name('Goods')->where(['id'=>$id, 'is_shelves'=>1, 'is_delete_time'=>0])->value('id')) return self::error('NOT_FOUND', 404);
        $validated = self::listQuery($query, ['cursor', 'limit']);
        if(isset($validated['error'])) return $validated;
        $builder = Db::name('GoodsSpecBase')->where('goods_id', $id)->order('id asc')->limit($validated['limit'] + 1);
        if($validated['cursor'] !== null) $builder->where('id', '>', $validated['cursor']);
        $rows = $builder->field('id,goods_id,price,original_price,inventory,coding')->select()->toArray();
        $specs = self::specifications([$id]);
        return self::page($rows, $validated['limit'], static fn(array $row): array => self::publicSku($row, $specs));
    }

    private static function productPage(array $rows, int $limit, array $config): array
    {
        $hasMore = count($rows) > $limit;
        if($hasMore) array_pop($rows);
        $ids = array_map('intval', array_column($rows, 'id'));
        $related = self::related($ids);
        $items = array_map(static fn(array $row): array => self::publicProduct($row, $related, $config), $rows);
        $last = empty($rows) ? null : end($rows);
        return self::ok(['items'=>$items, 'next_cursor'=>$hasMore && $last ? self::encodeCursor((int)$last['id']) : null, 'has_more'=>$hasMore]);
    }

    private static function related(array $productIds): array
    {
        if(empty($productIds)) return ['categories'=>[], 'skus'=>[], 'specs'=>[]];
        $categories = [];
        foreach(Db::name('GoodsCategoryJoin')->where('goods_id', 'in', $productIds)->field('goods_id,category_id')->order('id asc')->select()->toArray() as $row)
            $categories[(int)$row['goods_id']][] = (string)$row['category_id'];
        $skus = [];
        foreach(Db::name('GoodsSpecBase')->where('goods_id', 'in', $productIds)->field('id,goods_id,price,original_price,inventory,coding')->order('id asc')->select()->toArray() as $row)
            $skus[(int)$row['goods_id']][] = $row;
        return ['categories'=>$categories, 'skus'=>$skus, 'specs'=>self::specifications($productIds)];
    }

    private static function specifications(array $productIds): array
    {
        if(empty($productIds)) return [];
        $names = [];
        foreach(Db::name('GoodsSpecType')->where('goods_id', 'in', $productIds)->field('goods_id,name')->order('id asc')->select()->toArray() as $row)
            $names[(int)$row['goods_id']][] = (string)$row['name'];
        $values = [];
        foreach(Db::name('GoodsSpecValue')->where('goods_id', 'in', $productIds)->field('goods_id,goods_spec_base_id,value')->order('id asc')->select()->toArray() as $row)
            $values[(int)$row['goods_spec_base_id']][] = (string)$row['value'];
        return ['names'=>$names, 'values'=>$values];
    }

    private static function publicProduct(array $row, array $related, array $config): array
    {
        $id = (int)$row['id'];
        $result = [
            'product_id'=>(string)$id,
            'name'=>(string)$row['title'],
            'category_ids'=>$related['categories'][$id] ?? [],
            'description'=>(string)$row['simple_desc'],
            'active'=>true,
            'order_model'=>self::orderModel((int)$row['site_type']),
            'original_price_cents'=>self::cents($row['min_original_price']),
            'currency'=>'CNY',
            'tags'=>self::tags((string)$row['seo_keywords']),
            'metadata'=>[
                'goods_code'=>(string)$row['coding'],
                'inventory_unit'=>(string)$row['inventory_unit'],
                'updated_at'=>gmdate('c', max((int)$row['add_time'], (int)$row['upd_time'])),
            ],
            'skus'=>array_map(static fn(array $sku): array => self::publicSku($sku, $related['specs']), $related['skus'][$id] ?? []),
        ];
        $image = self::absoluteUrl((string)$row['images'], $config);
        if($image !== '') $result['image_url'] = $image;
        return $result;
    }

    private static function publicSku(array $row, array $specifications): array
    {
        $id = (int)$row['id'];
        $productId = (int)$row['goods_id'];
        $spec = [];
        foreach($specifications['values'][$id] ?? [] as $index=>$value)
            $spec[$specifications['names'][$productId][$index] ?? 'option_'.($index + 1)] = $value;
        $result = ['sku_id'=>(string)$id, 'sku_code'=>(string)$row['coding'], 'price_cents'=>self::cents($row['price']), 'original_price_cents'=>self::cents($row['original_price']), 'currency'=>'CNY', 'stock'=>max(0, (int)$row['inventory']), 'active'=>true];
        if(!empty($spec)) $result['spec'] = $spec;
        return $result;
    }

    private static function listQuery(array $query, array $allowed): array
    {
        foreach($query as $key=>$value)
            if(!in_array((string)$key, $allowed, true) || is_array($value)) return self::error('INVALID_QUERY', 400);
        $limit = self::DEFAULT_LIMIT;
        if(array_key_exists('limit', $query))
        {
            $raw = (string)$query['limit'];
            if(!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > self::MAX_LIMIT) return self::error('INVALID_LIMIT', 400);
            $limit = (int)$raw;
        }
        $cursor = null;
        if(array_key_exists('cursor', $query))
        {
            $cursor = self::decodeCursor((string)$query['cursor']);
            if($cursor === null) return self::error('INVALID_CURSOR', 400);
        }
        return ['code'=>0, 'limit'=>$limit, 'cursor'=>$cursor];
    }

    private static function page(array $rows, int $limit, callable $mapper): array
    {
        $hasMore = count($rows) > $limit;
        if($hasMore) array_pop($rows);
        $last = empty($rows) ? null : end($rows);
        return self::ok(['items'=>array_map($mapper, $rows), 'next_cursor'=>$hasMore && $last ? self::encodeCursor((int)$last['id']) : null, 'has_more'=>$hasMore]);
    }

    private static function productFields(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias.'.';
        return implode(',', array_map(static fn(string $field): string => $prefix.$field, ['id','site_type','title','simple_desc','coding','inventory_unit','images','min_original_price','seo_keywords','add_time','upd_time']));
    }

    private static function positiveId($value, bool $allowZero = false): ?int
    {
        if(is_array($value)) return null;
        $raw = (string)$value;
        if(!ctype_digit($raw)) return null;
        $id = (int)$raw;
        return ($allowZero ? $id >= 0 : $id > 0) ? $id : null;
    }

    private static function utcTimestamp($value): ?int
    {
        if(is_array($value)) return null;
        $raw = (string)$value;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $raw)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $raw, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $raw ? $date->getTimestamp() : null;
    }

    private static function encodeCursor(int $id): string { return rtrim(strtr(base64_encode((string)$id), '+/', '-_'), '='); }
    private static function decodeCursor(string $cursor): ?int
    {
        if($cursor === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $cursor)) return null;
        $decoded = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', (4-strlen($cursor)%4)%4), true);
        return $decoded !== false ? self::positiveId($decoded) : null;
    }
    private static function cents($value): int { return max(0, (int)round((float)$value * 100)); }
    private static function tags(string $value): array { return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,，\r\n]+/u', $value) ?: [])))); }
    private static function orderModel(int $siteType): string { return $siteType === 3 ? 'virtual' : ($siteType === 1 ? 'local_delivery' : ($siteType === 2 ? 'pickup' : 'physical')); }
    private static function absoluteUrl(string $value, array $config): string
    {
        if($value === '') return '';
        if(preg_match('#^https?://#i', $value)) return $value;
        return rtrim((string)($config['public_base_url'] ?? 'https://shop.denlaw.cn'), '/').'/'.ltrim($value, '/');
    }
    private static function ok(array $data): array { return ['code'=>0, 'status'=>200, 'data'=>$data]; }
    private static function error(string $error, int $status): array { return ['code'=>-1, 'status'=>$status, 'error'=>$error]; }
}
