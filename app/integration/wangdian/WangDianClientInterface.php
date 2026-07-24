<?php
namespace app\integration\wangdian;

interface WangDianClientInterface
{
    public function request(string $endpoint, array $businessParams = []): array;
}
