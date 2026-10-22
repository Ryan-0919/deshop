<?php

namespace app\integration\debao;

interface DebaoBindingRepositoryInterface
{
    public function transaction(callable $callback): array;
    public function insertChallenge(array $data): void;
    public function findChallenge(string $challengeId, ?string $debaoUserId = null, bool $forUpdate = false): ?array;
    public function updateChallenge(int $id, array $data): void;
    public function findActiveBindingByDebao(string $debaoUserId, bool $forUpdate = false): ?array;
    public function findActiveBindingByDeshop(int $deshopUserId, bool $forUpdate = false): ?array;
    public function insertBinding(array $data): int;
    public function updateBinding(int $id, array $data): void;
}
