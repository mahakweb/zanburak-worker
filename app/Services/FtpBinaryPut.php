<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reliable binary FTP PUT for large video files.
 * Paths are relative to disk "root" after chdir — do not prefix /public_html again
 * (many hosts chroot into public_html already).
 */
class FtpBinaryPut
{
    public static function put(array $diskConfig, string $remoteRelativePath, string $localAbsolutePath): void
    {
        if (!is_file($localAbsolutePath) || filesize($localAbsolutePath) < 1) {
            throw new RuntimeException('Local file missing for FTP upload');
        }

        $remoteRelativePath = ltrim(str_replace('\\', '/', $remoteRelativePath), '/');
        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                self::putViaPhpFtp($diskConfig, $remoteRelativePath, $localAbsolutePath);
                return;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('FTP binary put attempt failed', [
                    'attempt' => $attempt,
                    'remote' => $remoteRelativePath,
                    'error' => $e->getMessage(),
                ]);
                usleep(250000 * $attempt);
            }
        }

        throw new RuntimeException(
            'FTP upload failed after retries: ' . ($lastError?->getMessage() ?? 'unknown'),
            0,
            $lastError
        );
    }

    private static function putViaPhpFtp(array $diskConfig, string $remoteRelativePath, string $localAbsolutePath): void
    {
        $host = (string) ($diskConfig['host'] ?? '');
        $username = (string) ($diskConfig['username'] ?? '');
        $password = (string) ($diskConfig['password'] ?? '');
        $port = (int) ($diskConfig['port'] ?? 21);
        $timeout = (int) ($diskConfig['timeout'] ?? 600);
        $passive = (bool) ($diskConfig['passive'] ?? true);

        $conn = @ftp_connect($host, $port, $timeout);
        if ($conn === false) {
            throw new RuntimeException("FTP connect failed: {$host}");
        }

        try {
            if (!@ftp_login($conn, $username, $password)) {
                throw new RuntimeException('FTP login failed');
            }
            @ftp_pasv($conn, $passive);
            if (defined('FTP_USEPASVADDRESS')) {
                @ftp_set_option($conn, FTP_USEPASVADDRESS, false);
            }

            $root = trim(str_replace('\\', '/', (string) ($diskConfig['root'] ?? '')), '/');
            if ($root !== '') {
                if (!@ftp_chdir($conn, '/' . $root) && !@ftp_chdir($conn, $root)) {
                    // Account may already be chrooted into root.
                }
            }

            $home = @ftp_pwd($conn) ?: '/';
            $parent = dirname($remoteRelativePath);
            if ($parent !== '.' && $parent !== '') {
                self::ftpMkdirWalk($conn, $home, $parent);
            }
            @ftp_chdir($conn, $home);

            $ok = false;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                if (@ftp_put($conn, $remoteRelativePath, $localAbsolutePath, FTP_BINARY)) {
                    $ok = true;
                    break;
                }
                @ftp_chdir($conn, $home);
                usleep(250000 * $attempt);
            }
            if (!$ok) {
                $err = error_get_last();
                throw new RuntimeException('ftp_put failed: ' . ($err['message'] ?? 'unknown'));
            }
        } finally {
            @ftp_close($conn);
        }
    }

    private static function ftpMkdirWalk($conn, string $home, string $relativeDir): void
    {
        $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
        if ($relativeDir === '') {
            return;
        }

        @ftp_chdir($conn, $home);
        foreach (explode('/', $relativeDir) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if (@ftp_chdir($conn, $part)) {
                continue;
            }
            if (!@ftp_mkdir($conn, $part) && !@ftp_chdir($conn, $part)) {
                throw new RuntimeException('FTP mkdir failed at ' . $part);
            }
            if (!@ftp_chdir($conn, $part)) {
                throw new RuntimeException('FTP chdir failed after mkdir: ' . $part);
            }
        }
        @ftp_chdir($conn, $home);
    }
}
