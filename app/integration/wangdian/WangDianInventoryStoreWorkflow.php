<?php
namespace app\integration\wangdian;

use app\service\GoodsService;
use think\facade\Db;

trait WangDianInventoryStoreWorkflow
{
    public function applyInventory(array $record, int $warehouseId): int
    {
        $specNo = trim((string)($record['spec_no'] ?? ''));
        $externalGoodsId = trim((string)($record['goods_id'] ?? ''));
        if($specNo === '' || $externalGoodsId === '' || !isset($record['sync_stock']))
        {
            throw new \InvalidArgumentException('The inventory record is missing spec_no, goods_id or sync_stock.');
        }
        $inventory = WangDianInventoryValue::parse($record['sync_stock']);

        $matches = array_values(array_filter(
            Db::name('GoodsSpecBase')->where(['coding'=>$specNo])->select()->toArray(),
            static fn(array $item): bool => (string)$item['coding'] === $specNo
        ));
        if(count($matches) !== 1)
        {
            throw new \RuntimeException('SKU '.$specNo.' must match exactly one ShopXO specification.');
        }
        $specBase = $matches[0];
        $goodsId = (int)$specBase['goods_id'];
        if($externalGoodsId !== (string)$goodsId)
        {
            throw new \RuntimeException('SKU '.$specNo.' belongs to ShopXO goods '.$goodsId.', not '.$externalGoodsId.'.');
        }

        Db::startTrans();
        try {
            $goods = Db::name('Goods')->where(['id'=>$goodsId])->field('id')->lock(true)->find();
            if(empty($goods))
            {
                throw new \RuntimeException('ShopXO goods '.$goodsId.' was not found.');
            }
            $lockedSpec = Db::name('GoodsSpecBase')
                ->where(['id'=>$specBase['id'], 'goods_id'=>$goodsId])
                ->field('id')
                ->lock(true)
                ->find();
            if(empty($lockedSpec))
            {
                throw new \RuntimeException('ShopXO SKU '.$specNo.' was not found while applying inventory.');
            }
            if(Db::name('GoodsSpecBase')->where(['id'=>$specBase['id'], 'goods_id'=>$goodsId])->update(['inventory'=>$inventory]) === false)
            {
                throw new \RuntimeException('Failed to update ShopXO SKU inventory.');
            }

            if($warehouseId > 0)
            {
                $this->updateWarehouseInventory($warehouseId, $goodsId, (int)$specBase['id'], $inventory);
            }

            $goodsInventory = $this->sumLockedInventory('GoodsSpecBase', ['goods_id'=>$goodsId]);
            if(Db::name('Goods')->where(['id'=>$goodsId])->update(['inventory'=>$goodsInventory, 'upd_time'=>time()]) === false)
            {
                throw new \RuntimeException('Failed to update ShopXO goods inventory.');
            }
            Db::commit();
            return $goodsId;
        } catch(\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    private function updateWarehouseInventory(int $warehouseId, int $goodsId, int $specBaseId, int $inventory): void
    {
        $warehouseGoods = Db::name('WarehouseGoods')->where([
            'warehouse_id'=>$warehouseId,
            'goods_id'=>$goodsId,
        ])->lock(true)->find();
        if(empty($warehouseGoods))
        {
            throw new \RuntimeException('ShopXO warehouse '.$warehouseId.' is not configured for goods '.$goodsId.'.');
        }

        $actual = GoodsService::GoodsSpecificationsActual($goodsId);
        $specValue = 'default';
        foreach((array)($actual['value'] ?? []) as $item)
        {
            if((int)($item['base_id'] ?? 0) === $specBaseId)
            {
                $specValue = (string)($item['value'] ?? 'default');
                break;
            }
        }
        $md5Key = md5($specValue === 'default' ? 'default' : str_replace(GoodsService::$goods_spec_to_string_separator, '', $specValue));
        $warehouseSpec = Db::name('WarehouseGoodsSpec')->where([
            'warehouse_goods_id'=>$warehouseGoods['id'],
            'warehouse_id'=>$warehouseId,
            'goods_id'=>$goodsId,
            'md5_key'=>$md5Key,
        ])->lock(true)->find();
        if(empty($warehouseSpec))
        {
            throw new \RuntimeException('ShopXO warehouse specification was not found for SKU '.$specBaseId.'.');
        }
        if(Db::name('WarehouseGoodsSpec')->where(['id'=>$warehouseSpec['id']])->update(['inventory'=>$inventory]) === false)
        {
            throw new \RuntimeException('Failed to update ShopXO warehouse SKU inventory.');
        }

        $warehouseInventory = $this->sumLockedInventory('WarehouseGoodsSpec', ['warehouse_goods_id'=>$warehouseGoods['id']]);
        if(Db::name('WarehouseGoods')->where(['id'=>$warehouseGoods['id']])->update([
            'inventory'=>$warehouseInventory,
            'upd_time'=>time(),
        ]) === false)
        {
            throw new \RuntimeException('Failed to update ShopXO warehouse goods inventory.');
        }
    }

    private function sumLockedInventory(string $table, array $where): int
    {
        $rows = Db::name($table)->where($where)->field('inventory')->lock(true)->select()->toArray();
        $total = 0;
        foreach($rows as $row)
        {
            $total += WangDianInventoryValue::parse($row['inventory'] ?? null);
            WangDianInventoryValue::parse($total);
        }
        return $total;
    }

}
