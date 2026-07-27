<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\integration\wangdian\WangDianCronAccessPolicy;

function assertWangDianCronAccessSame($expected, $actual, string $message): void
{
    if($expected !== $actual)
    {
        throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}

assertWangDianCronAccessSame(
    true,
    WangDianCronAccessPolicy::allows('127.0.0.1', '', '', ['127.0.0.1', '::1']),
    'A loopback scheduler must be allowed by the IP allowlist'
);
assertWangDianCronAccessSame(
    false,
    WangDianCronAccessPolicy::allows('203.0.113.10', '', '', ['127.0.0.1', '::1']),
    'An untrusted remote scheduler without a token must be denied'
);
assertWangDianCronAccessSame(
    true,
    WangDianCronAccessPolicy::allows('203.0.113.10', 'secret-token', 'secret-token', ['127.0.0.1']),
    'A remote scheduler with the configured token must be allowed'
);
assertWangDianCronAccessSame(
    false,
    WangDianCronAccessPolicy::allows('203.0.113.10', 'wrong-token', 'secret-token', ['127.0.0.1']),
    'A remote scheduler with the wrong token must be denied'
);
assertWangDianCronAccessSame(
    true,
    WangDianCronAccessPolicy::allows('10.0.0.8', '', '', ['10.0.0.8']),
    'An explicitly allowlisted scheduler IP must be allowed'
);

echo "WangDianCronAccessPolicy tests passed\n";
