<?php
namespace app\integration\wangdian;

interface WangDianTransportInterface
{
    public function post(string $url, array $form): string;
}
