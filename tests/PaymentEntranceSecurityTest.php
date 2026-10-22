<?php

require_once dirname(__DIR__).'/vendor/autoload.php';

use app\service\ArticleService;
use app\service\PaymentService;
use app\service\QuerySafetyService;
use app\service\SearchService;

function assertSecuritySame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message.'; expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

assertSecuritySame(true, PaymentService::PaymentIdentifierValid('WeixinAppMini'), 'Known payment identifiers must remain valid');
assertSecuritySame(true, PaymentService::PaymentIdentifierValid('provider_v2'), 'Underscored payment identifiers must remain valid');
assertSecuritySame(false, PaymentService::PaymentIdentifierValid("a');eval(\$_GET[1]);#"), 'PHP payloads must be rejected');
assertSecuritySame(false, PaymentService::PaymentIdentifierValid('../shell'), 'Path traversal must be rejected');
assertSecuritySame(false, PaymentService::PaymentIdentifierValid(''), 'Empty identifiers must be rejected');

assertSecuritySame('id asc', ArticleService::ArticleByOrder(['order_by_key'=>'new', 'order_by_val'=>'ASC']), 'Valid ascending sort must work');
assertSecuritySame('access_count desc', ArticleService::ArticleByOrder(['order_by_key'=>'hot', 'order_by_val'=>'desc']), 'Valid descending sort must work');
assertSecuritySame('id desc', ArticleService::ArticleByOrder(['order_by_key'=>'new', 'order_by_val'=>'desc,(select sleep(1))']), 'Injected sort expressions must fall back safely');
assertSecuritySame('id desc', ArticleService::ArticleByOrder(['order_by_key'=>'unknown', 'order_by_val'=>'asc']), 'Unknown sort fields must fall back safely');

assertSecuritySame('id desc', QuerySafetyService::OrderBy('id desc,(select sleep(1))', ['id', 'access_count'], 'id desc'), 'Injected direct order expressions must fall back safely');
assertSecuritySame('access_count,id desc', QuerySafetyService::OrderBy('access_count,id DESC', ['id', 'access_count'], 'id desc'), 'Known multi-column sorts must remain compatible');
assertSecuritySame('*', QuerySafetyService::FieldList('id,(select password)', ['id', 'title'], '*'), 'Injected field lists must fall back safely');
assertSecuritySame('id,title', QuerySafetyService::FieldList('id, title', ['id', 'title'], '*'), 'Known field lists must remain compatible');
assertSecuritySame('gci.category_id', QuerySafetyService::FieldList('gci.category_id,(select 1)', ['gci.category_id'], 'gci.category_id'), 'Injected layout category fields must fall back safely');
assertSecuritySame('', QuerySafetyService::Direction('desc, sleep(1)'), 'Injected directions must be rejected');

$searchOrder = SearchService::SearchCompatibilityOrderBy('sales_count', 'DESC');
assertSecuritySame('g.sales_count desc', $searchOrder['order_by'], 'Existing app search sorting must remain compatible');
assertSecuritySame(null, SearchService::SearchCompatibilityOrderBy('id,(select sleep(1))', 'desc'), 'Injected search fields must be rejected');
assertSecuritySame(null, SearchService::SearchCompatibilityOrderBy('id', 'desc, sleep(1)'), 'Injected search directions must be rejected');

$thrown = false;
try {
    PaymentService::EntranceFileData("a');eval(\$_GET[1]);#", 'order');
} catch(InvalidArgumentException $e) {
    $thrown = true;
}
assertSecuritySame(true, $thrown, 'Malformed payment identifiers must never reach filename generation');

echo "PaymentEntranceSecurityTest: OK\n";
