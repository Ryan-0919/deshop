<?php

namespace app\integration\debao;

use think\facade\Db;

final class DebaoBindingDbRepository implements DebaoBindingRepositoryInterface
{
    public function transaction(callable $callback): array
    {
        return Db::transaction(fn() => $callback($this));
    }

    public function insertChallenge(array $data): void
    {
        Db::name('DebaoBindingChallenge')->insert($data);
    }

    public function findChallenge(string $challengeId, ?string $debaoUserId = null, bool $forUpdate = false): ?array
    {
        $query = Db::name('DebaoBindingChallenge')->where(['challenge_id'=>$challengeId]);
        if($debaoUserId !== null) $query->where(['debao_user_id'=>$debaoUserId]);
        if($forUpdate) $query->lock(true);
        $row = $query->find();
        return empty($row) ? null : $row;
    }

    public function updateChallenge(int $id, array $data): void
    {
        Db::name('DebaoBindingChallenge')->where(['id'=>$id])->update($data);
    }

    public function findActiveBindingByDebao(string $debaoUserId, bool $forUpdate = false): ?array
    {
        return $this->findBinding(['debao_user_id'=>$debaoUserId, 'status'=>'active'], $forUpdate);
    }

    public function findActiveBindingByDeshop(int $deshopUserId, bool $forUpdate = false): ?array
    {
        return $this->findBinding(['deshop_user_id'=>$deshopUserId, 'status'=>'active'], $forUpdate);
    }

    public function insertBinding(array $data): int
    {
        return (int)Db::name('DebaoBinding')->insertGetId($data);
    }

    public function updateBinding(int $id, array $data): void
    {
        Db::name('DebaoBinding')->where(['id'=>$id])->update($data);
    }

    private function findBinding(array $where, bool $forUpdate): ?array
    {
        $query = Db::name('DebaoBinding')->where($where);
        if($forUpdate) $query->lock(true);
        $row = $query->find();
        return empty($row) ? null : $row;
    }
}
