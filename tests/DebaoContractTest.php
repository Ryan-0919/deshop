<?php

declare(strict_types=1);

$path = __DIR__.'/../docs/integrations/debao-openapi.json';
$json = file_get_contents($path);
if($json === false)
{
    throw new RuntimeException('Unable to read OpenAPI contract.');
}
$document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

assert($document['openapi'] === '3.0.3');
assert(isset($document['paths']['/products']));
assert(!isset($document['paths']['/products']['get']['security']));
assert(isset($document['paths']['/products/{product_id}/skus']['get']['responses']['401']));
assert(isset($document['paths']['/bindings/challenges']));
assert(isset($document['paths']['/addresses/{address_id}']));
assert(isset($document['paths']['/sessions/checkout']));
assert(isset($document['paths']['/orders/{order_id}/pay']));
assert(isset($document['paths']['/webhooks/debao-events']['post']));
assert(isset($document['components']['securitySchemes']['debaoHmac']));
assert($document['components']['schemas']['WebhookEvent']['properties']['event_id']['type'] === 'string');
assert($document['components']['schemas']['Sku']['properties']['price_cents']['type'] === 'integer');
assert($document['components']['schemas']['Sku']['properties']['original_price_cents']['type'] === 'integer');
assert($document['components']['schemas']['Sku']['properties']['currency']['pattern'] === '^[A-Z]{3}$');
assert($document['components']['schemas']['Product']['properties']['image_url']['format'] === 'uri');
assert($document['components']['schemas']['Product']['properties']['tags']['type'] === 'array');
assert($document['components']['schemas']['Product']['properties']['features']['type'] === 'object');
assert($document['components']['schemas']['Product']['properties']['metadata']['type'] === 'object');
assert($document['components']['schemas']['Product']['properties']['original_price_cents']['type'] === 'integer');
assert($document['components']['schemas']['Product']['properties']['currency']['pattern'] === '^[A-Z]{3}$');
assert($document['components']['schemas']['Order']['properties']['created_at']['format'] === 'date-time');
assert($document['components']['schemas']['SsoSessionRequest']['required'] === ['debao_user_id', 'redirect_uri']);
assert($document['components']['schemas']['CheckoutRequest']['properties']['items']['items']['required'] === ['sku_id', 'quantity']);
assert($document['components']['schemas']['Session']['properties']['total_cents']['type'] === 'integer');
assert($document['components']['schemas']['Session']['properties']['payment_required']['type'] === 'boolean');
assert($document['components']['schemas']['Payment']['required'] === ['order_id', 'amount_cents', 'payment_provider', 'payment_intent_id']);
assert($document['components']['responses']['Payment']['content']['application/json']['schema']['properties']['data']['$ref'] === '#/components/schemas/Payment');

echo "DebaoContractTest: OK\n";
