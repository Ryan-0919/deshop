<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\storage\OssHttpTransport;
use app\storage\OssStorage;

$root = dirname(__DIR__);
$env = parse_ini_file($root.'/.env', false, INI_SCANNER_RAW);
$config = [
    'access_key_id' => trim((string)($env['OSS_ACCESS_KEY_ID'] ?? '')),
    'access_key_secret' => trim((string)($env['OSS_ACCESS_KEY_SECRET'] ?? '')),
    'bucket' => trim((string)($env['OSS_BUCKET'] ?? '')),
    'endpoint' => trim((string)($env['OSS_ENDPOINT'] ?? '')),
    'public_url' => trim((string)($env['OSS_PUBLIC_URL'] ?? '')),
    'connect_timeout' => 5,
    'timeout' => 30,
];

$storage = new OssStorage($config, new OssHttpTransport($config));
$localFile = tempnam(sys_get_temp_dir(), 'shopxo-oss-health-');
$objectPath = '/static/upload/health/'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.txt';

try
{
    file_put_contents($localFile, 'ShopXO OSS health check '.date(DATE_ATOM));
    $publicUrl = $storage->put($localFile, $objectPath);

    $curl = curl_init($publicUrl);
    curl_setopt_array($curl, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if($status !== 200)
    {
        throw new RuntimeException('OSS public read check failed with HTTP '.$status.'.');
    }

    $storage->delete($objectPath);
    echo "OSS upload, public read and delete checks passed\n";
} finally {
    @unlink($localFile);
    try
    {
        $storage->delete($objectPath);
    } catch(Throwable $e) {
    }
}

