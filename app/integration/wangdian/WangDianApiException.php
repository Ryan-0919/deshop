<?php
namespace app\integration\wangdian;

final class WangDianApiException extends \RuntimeException
{
    private array $response;

    public function __construct(string $message, array $response)
    {
        parent::__construct($message);
        $this->response = $response;
    }

    public function response(): array
    {
        return $this->response;
    }
}
