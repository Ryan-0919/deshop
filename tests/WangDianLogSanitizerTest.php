<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianLogSanitizer;

function assertWangDianLogSanitizerSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

$sanitized = WangDianLogSanitizer::sanitize([
    'message'=>'receiver_mobile=13800138000, address: Beijing Road 1, appsecret=plain-secret',
    'receiver_mobile'=>'13800138000',
    'receiver_address'=>'Beijing Road 1',
    'nested'=>[
        'AccessKeySecret'=>'another-secret',
        'signature'=>'signature-secret',
        'sign_method'=>'md5',
        'token_expiry'=>'tomorrow',
        'password_hash'=>'hash',
        'error'=>'联系电话：13912345678；收货地址：Shanghai Road 2',
    ],
    'credential_message'=>'signature=raw-signature, "token_expiry":"tomorrow"',
    'external_message'=>'{"receiver_address":"Beijing Road 1"}',
    'chinese_json'=>'{"收货地址":"北京市朝阳区测试路 1 号","note":"safe"}',
    'escaped_json'=>'{\"receiver_address\":\"Road \\\"A\\\" 2\",\"note\":\"safe\"}',
    'chinese_text'=>'收货地址：北京市朝阳区测试路 2 号',
]);

assertWangDianLogSanitizerSame('[REDACTED]', $sanitized['receiver_mobile'] ?? null, 'Sensitive phone fields must be fully redacted');
assertWangDianLogSanitizerSame('[REDACTED]', $sanitized['receiver_address'] ?? null, 'Sensitive address fields must be fully redacted');
assertWangDianLogSanitizerSame('[REDACTED]', $sanitized['nested']['AccessKeySecret'] ?? null, 'Secret fields must be fully redacted');
foreach(['signature', 'sign_method', 'token_expiry', 'password_hash'] as $sensitiveKey)
{
    assertWangDianLogSanitizerSame('[REDACTED]', $sanitized['nested'][$sensitiveKey] ?? null, 'Sensitive fragments anywhere in field names must be redacted');
}
assertWangDianLogSanitizerSame(false, str_contains($sanitized['message'] ?? '', '13800138000'), 'Phone numbers embedded in messages must be masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['message'] ?? '', 'Beijing Road 1'), 'Addresses embedded in messages must be masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['message'] ?? '', 'plain-secret'), 'Secrets embedded in messages must be masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['nested']['error'] ?? '', 'Shanghai Road 2'), 'Chinese address labels must be masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['credential_message'] ?? '', 'raw-signature'), 'Sensitive free-text field names must be masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['credential_message'] ?? '', 'tomorrow'), 'Sensitive free-text suffix fields must be masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['external_message'] ?? '', 'Beijing Road 1'), 'JSON-style address fields embedded in messages must be masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['chinese_json'] ?? '', '测试路'), 'Chinese JSON address keys must be masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['escaped_json'] ?? '', 'Road'), 'Escaped JSON address values must be parsed and masked');
assertWangDianLogSanitizerSame(false, str_contains($sanitized['chinese_text'] ?? '', '测试路'), 'Chinese free-text addresses must be masked');

echo "WangDianLogSanitizer tests passed\n";
