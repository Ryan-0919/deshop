<?php
namespace app\integration\wangdian;


final class WangDianStore implements WangDianStoreInterface
{
    use WangDianOrderStoreWorkflow;
    use WangDianAftersaleStoreWorkflow;
    use WangDianSyncLogStoreWorkflow;
    use WangDianLogisticsStoreWorkflow;
    use WangDianInventoryStoreWorkflow;

    private const PROCESSING_LEASE_SECONDS = 3600;
    private const MAX_RETRY_SECONDS = 3600;
    private const ORDER_RECHECK_SECONDS = 86400;

    private function truncate(string $value, int $maxCharacters): string
    {
        if(preg_match('/^.{0,'.$maxCharacters.'}/us', $value, $matches) === 1)
        {
            return $matches[0];
        }
        return '';
    }
}
