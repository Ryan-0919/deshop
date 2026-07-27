<?php
namespace app\integration\wangdian;

final class WangDianBatchPushDefinition
{
    private string $endpoint;
    private string $payloadName;
    private string $attemptIdField;
    private string $businessType;
    private array $errorIdFields;
    private string $defaultError;
    private array $businessParams;
    private bool $allowImplicitSingleSuccess;

    private function __construct(
        string $endpoint,
        string $payloadName,
        string $attemptIdField,
        string $businessType,
        array $errorIdFields,
        string $defaultError,
        array $businessParams,
        bool $allowImplicitSingleSuccess
    ) {
        $this->endpoint = $endpoint;
        $this->payloadName = $payloadName;
        $this->attemptIdField = $attemptIdField;
        $this->businessType = $businessType;
        $this->errorIdFields = $errorIdFields;
        $this->defaultError = $defaultError;
        $this->businessParams = $businessParams;
        $this->allowImplicitSingleSuccess = $allowImplicitSingleSuccess;
    }

    public static function orders(string $shopNo): self
    {
        return new self(
            'trade_push.php',
            'trade_list',
            'order_no',
            'order_push',
            ['tid', 'order_no'],
            'Order synchronization failed.',
            ['shop_no'=>$shopNo, 'switch'=>'0'],
            false
        );
    }

    public static function aftersales(): self
    {
        return new self(
            'sales_refund_push.php',
            'api_refund_list',
            'refund_no',
            'aftersale_push',
            ['refund_no', 'src_no'],
            'Aftersale synchronization failed.',
            [],
            true
        );
    }

    public function endpoint(): string { return $this->endpoint; }
    public function payloadName(): string { return $this->payloadName; }
    public function attemptIdField(): string { return $this->attemptIdField; }
    public function businessType(): string { return $this->businessType; }
    public function errorIdFields(): array { return $this->errorIdFields; }
    public function defaultError(): string { return $this->defaultError; }
    public function businessParams(): array { return $this->businessParams; }
    public function allowImplicitSingleSuccess(): bool { return $this->allowImplicitSingleSuccess; }
}
