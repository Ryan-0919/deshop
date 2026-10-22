<?php

declare(strict_types=1);

require_once __DIR__.'/../app/integration/debao/DebaoNonceStore.php';

use app\integration\debao\DebaoNonceStore;

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'debao-nonce-'.bin2hex(random_bytes(8));
$hash = hash('sha256', 'client\nkey\nnonce');

if(!DebaoNonceStore::claim($hash, 1600, 1000, $root)) throw new RuntimeException('First nonce claim must succeed.');
if(DebaoNonceStore::claim($hash, 1600, 1001, $root)) throw new RuntimeException('Repeated nonce must be rejected.');
if(!DebaoNonceStore::claim($hash, 2200, 1601, $root)) throw new RuntimeException('Expired nonce must be reclaimable.');
if(DebaoNonceStore::claim('invalid', 2200, 1601, $root)) throw new RuntimeException('Invalid nonce hash must fail closed.');
if(DebaoNonceStore::claim(hash('sha256', 'expired'), 1600, 1601, $root)) throw new RuntimeException('Already expired nonce must fail closed.');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
rmdir($root);

echo "DebaoNonceStoreTest: OK\n";
