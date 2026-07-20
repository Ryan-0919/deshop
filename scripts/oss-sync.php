<?php

require dirname(__DIR__).'/vendor/autoload.php';

use app\storage\OssHttpTransport;
use app\storage\OssStorage;

$root = dirname(__DIR__);
$envFile = $root.'/.env';
if(!is_file($envFile))
{
    fwrite(STDERR, "Missing .env file.\n");
    exit(1);
}

$env = parse_ini_file($envFile, false, INI_SCANNER_RAW);
$config = [
    'access_key_id' => trim((string)($env['OSS_ACCESS_KEY_ID'] ?? '')),
    'access_key_secret' => trim((string)($env['OSS_ACCESS_KEY_SECRET'] ?? '')),
    'bucket' => trim((string)($env['OSS_BUCKET'] ?? '')),
    'endpoint' => trim((string)($env['OSS_ENDPOINT'] ?? '')),
    'public_url' => trim((string)($env['OSS_PUBLIC_URL'] ?? '')),
    'connect_timeout' => 5,
    'timeout' => 300,
];

$storage = new OssStorage($config, new OssHttpTransport($config));
$uploadRoot = $root.'/public/static/upload';
if(!is_dir($uploadRoot))
{
    fwrite(STDERR, "Attachment directory does not exist: {$uploadRoot}\n");
    exit(1);
}

$count = 0;
$bytes = 0;
$markerRoot = $root.'/runtime/oss-sync';
if(!is_dir($markerRoot))
{
    mkdir($markerRoot, 0775, true);
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS)
);

foreach($iterator as $file)
{
    if(!$file->isFile())
    {
        continue;
    }

    $localFile = $file->getPathname();
    $relativePath = '/'.ltrim(str_replace('\\', '/', substr($localFile, strlen($root.'/public'))), '/');
    $storage->put($localFile, $relativePath);
    file_put_contents(
        $markerRoot.'/'.sha1($relativePath).'.txt',
        (string)$file->getMTime().':'.(string)$file->getSize(),
        LOCK_EX
    );
    $count++;
    $bytes += $file->getSize();

    if($count % 100 === 0)
    {
        echo "Uploaded {$count} files\n";
    }
}

echo 'OSS sync complete: '.$count.' files, '.$bytes." bytes\n";
