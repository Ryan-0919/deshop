<?php

declare(strict_types=1);

$base = rtrim((string)getenv('DEBAO_TEST_BASE_URL'), '/');
$clientId = (string)getenv('DEBAO_TEST_CLIENT_ID');
$keyId = (string)getenv('DEBAO_TEST_KEY_ID');
$secret = (string)getenv('DEBAO_TEST_SECRET');
if($base === '' || $clientId === '' || $keyId === '' || $secret === '')
{
    echo "DebaoCatalogHttpIntegrationTest: SKIP (production credentials not configured)\n";
    exit(0);
}

function requestCatalog(string $path, array $query = [], bool $signed = true): array
{
    global $base, $clientId, $keyId, $secret;
    ksort($query, SORT_STRING);
    $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $url = $base.$path.($canonicalQuery === '' ? '' : '?'.$canonicalQuery);
    $headers = ['Accept: application/json'];
    if($signed)
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $signingPath = (string)parse_url($base.$path, PHP_URL_PATH);
        $canonical = implode("\n", [$clientId,$keyId,$timestamp,$nonce,'GET',$signingPath,$canonicalQuery,hash('sha256','')]);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');
        $headers = array_merge($headers, ["X-Debao-Client-Id: {$clientId}","X-Debao-Key-Id: {$keyId}","X-Debao-Timestamp: {$timestamp}","X-Debao-Nonce: {$nonce}","X-Debao-Signature: {$signature}"]);
    }
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30]);
    $body = curl_exec($handle);
    if($body === false) throw new RuntimeException('HTTP request failed: '.curl_error($handle));
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status'=>$status, 'json'=>json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR)];
}
function expectStatus(int $expected, array $response, string $case): void { if($response['status'] !== $expected) throw new RuntimeException("{$case}: expected {$expected}, got {$response['status']}"); }

expectStatus(401, requestCatalog('/categories', [], false), 'Unauthorized');
$categories = requestCatalog('/categories', ['limit'=>'1']); expectStatus(200, $categories, 'Categories');
if(!isset($categories['json']['data']['items'],$categories['json']['data']['has_more'])) throw new RuntimeException('Category page shape invalid.');
expectStatus(400, requestCatalog('/categories', ['limit'=>'0']), 'Invalid limit');
expectStatus(400, requestCatalog('/categories', ['cursor'=>'***']), 'Invalid cursor');
expectStatus(200, requestCatalog('/categories', ['limit'=>'100']), 'Maximum limit');

$products = requestCatalog('/products', ['limit'=>'2']); expectStatus(200, $products, 'Products');
$items = $products['json']['data']['items'] ?? null;
if(!is_array($items) || $items === []) throw new RuntimeException('Production catalog returned no products.');
$first = $items[0];
foreach(['product_id','name','active','skus'] as $field) if(!array_key_exists($field, $first)) throw new RuntimeException('Product field missing: '.$field);
expectStatus(200, requestCatalog('/products', ['updated_after'=>'2999-01-01T00:00:00Z']), 'Empty products');
expectStatus(400, requestCatalog('/products', ['updated_after'=>'invalid']), 'Invalid updated_after');
expectStatus(400, requestCatalog('/products/not-a-number'), 'Invalid product id');
expectStatus(404, requestCatalog('/products/999999999'), 'Missing product');
expectStatus(200, requestCatalog('/products/'.$first['product_id']), 'Product detail');
expectStatus(200, requestCatalog('/products/'.$first['product_id'].'/skus', ['limit'=>'100']), 'Product SKUs');
expectStatus(404, requestCatalog('/products/999999999/skus'), 'Missing product SKUs');
if(!empty($products['json']['data']['has_more']))
{
    $next = requestCatalog('/products', ['cursor'=>(string)$products['json']['data']['next_cursor'],'limit'=>'2']);
    expectStatus(200, $next, 'Next product page');
}
echo "DebaoCatalogHttpIntegrationTest: OK\n";
