<?php

namespace app\integration\debao;

final class DebaoNonceStore
{
    public static function claim(string $nonceHash, int $expiresAt, int $now, ?string $root = null): bool
    {
        if(!preg_match('/^[a-f0-9]{64}$/', $nonceHash) || $expiresAt <= $now)
        {
            return false;
        }

        $root = $root ?? runtime_path().'debao_nonce';
        $directory = rtrim($root, '/\\').DIRECTORY_SEPARATOR.substr($nonceHash, 0, 2);
        if(!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
        {
            return false;
        }

        $lock = @fopen($directory.DIRECTORY_SEPARATOR.'.lock', 'c');
        if($lock === false || !@flock($lock, LOCK_EX))
        {
            if(is_resource($lock)) fclose($lock);
            return false;
        }

        try {
            self::removeExpired($directory, $now);
            $path = $directory.DIRECTORY_SEPARATOR.$nonceHash;
            if(is_file($path))
            {
                $storedExpiry = (int)@file_get_contents($path);
                if($storedExpiry >= $now) return false;
                @unlink($path);
            }

            $handle = @fopen($path, 'x');
            if($handle === false) return false;
            $written = fwrite($handle, (string)$expiresAt);
            fclose($handle);
            if($written === false)
            {
                @unlink($path);
                return false;
            }
            @chmod($path, 0600);
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function removeExpired(string $directory, int $now): void
    {
        $files = @scandir($directory);
        if(!is_array($files)) return;
        $checked = 0;
        foreach($files as $file)
        {
            if($checked++ >= 200) break;
            if(!preg_match('/^[a-f0-9]{64}$/', $file)) continue;
            $path = $directory.DIRECTORY_SEPARATOR.$file;
            if((int)@file_get_contents($path) < $now) @unlink($path);
        }
    }
}
