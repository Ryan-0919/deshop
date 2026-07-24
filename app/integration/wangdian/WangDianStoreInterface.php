<?php
namespace app\integration\wangdian;

interface WangDianStoreInterface
{
    public function pendingOrders(int $syncFrom, int $limit, int $now): array;

    public function startAttempt(string $businessType, string $businessKey, int $orderId, array $requestSummary, int $now): bool;

    public function markSuccess(string $businessType, string $businessKey, array $responseSummary, int $now): void;

    public function markFailure(
        string $businessType,
        string $businessKey,
        string $error,
        int $now,
        array $responseSummary = []
    ): void;

    public function applyLogistics(array $record, array $expressMap): int;

    public function applyInventory(array $record, int $warehouseId): int;
}
