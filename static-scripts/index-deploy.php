<?php
/**
 * Zanburak Video Streaming & Download Host Gateway
 *
 * Place this file at: public_html/index.php on static.zanburak.ir & dl.zanburak.ir
 *
 * Responsibilities:
 *  1. OPTIONS: Instant CORS preflight response for web players (with credentials reflection)
 *  2. POST: Fast ZIP deployment (single target or multi-target bundle)
 *  3. GET / HEAD:
 *     - Secure signed HLS playback (playlists .m3u8, video segments .ts, AES-128 keys .key, thumbnails)
 *     - Direct signed MP4 file downloads
 *     - HTTP 206 Partial Content (Byte-Range streaming for fast seeking & scrubbing)
 *     - Hotlinking & scraping protection (Origin & Referer domain validation)
 *     - Zero RAM overhead chunked streaming
 */

declare(strict_types=1);

// Default shared secret between backend, worker, and download hosts.
// Can also be overridden by setting environment variable VIDEO_STREAM_SECRET or STATIC_DEPLOY_SECRET.
$DEPLOY_SECRET = 'uftj30zp4skhe6b7ngl18qymiv9rcxao5wd2';
$MAX_DEPLOY_SKEW = 86400; // 24 hours to accommodate large multi-gigabyte uploads

// Allowed origin patterns for CORS and Hotlink prevention
$ALLOWED_ORIGIN_PATTERNS = [
    '#^https?://([a-zA-Z0-9-]+\.)?zanburak\.ir(:[0-9]+)?$#i',
    '#^https?://localhost(:[0-9]+)?$#i',
    '#^https?://127\.0\.0\.1(:[0-9]+)?$#i',
];

if (!function_exists('hash_equals')) {
    function hash_equals($a, $b)
    {
        if (!is_string($a) || !is_string($b) || strlen($a) !== strlen($b)) {
            return false;
        }
        $r = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $r |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $r === 0;
    }
}

/**
 * Determine CORS allowed origin.
 * When Origin is present, always reflect it so withCredentials=true mode never fails.
 */
function resolve_cors_origin(): string
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        return $origin;
    }
    return '*';
}

/**
 * Send standard CORS headers.
 */
function apply_cors_headers(string $origin = '*'): void
{
    header('Access-Control-Allow-Origin: ' . $origin);
    if ($origin !== '*') {
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Origin, Range, X-Requested-With, Content-Type, Accept, X-Zanburak-Player, Authorization');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges, Content-Disposition');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Output JSON error and exit.
 */
function respond_fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'code' => $code,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$corsOrigin = resolve_cors_origin();
apply_cors_headers($corsOrigin);

$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// =========================================================================
// 1. OPTIONS (CORS Preflight)
// =========================================================================
if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Secret resolution
$secret = getenv('VIDEO_STREAM_SECRET') ?: getenv('STATIC_DEPLOY_SECRET');
if (!$secret) {
    $secret = $DEPLOY_SECRET;
}

// =========================================================================
// 2. POST (Worker Deployment / Fast ZIP Unpacker)
// =========================================================================
if ($requestMethod === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $target = (string) ($_POST['target'] ?? '');
    $ts = (string) ($_POST['ts'] ?? '');
    $sig = (string) ($_POST['sig'] ?? '');
    $target = str_replace('\\', '/', $target);
    $target = trim($target, '/');

    $isBundle = ($target === 'bundle');
    if (!$isBundle && ($target === '' || strpos($target, '..') !== false || !preg_match('#^(stream|download|trailer|raw)/[A-Za-z0-9._/-]+$#', $target))) {
        respond_fail(422, 'Invalid target');
    }

    if ($ts === '' || $sig === '' || !ctype_digit($ts)) {
        respond_fail(401, 'Missing signature');
    }

    if (abs(time() - (int) $ts) > $MAX_DEPLOY_SKEW) {
        respond_fail(401, 'Signature expired');
    }

    $expected = hash_hmac('sha256', $ts . "\n" . $target, $secret);
    if (!hash_equals($expected, $sig)) {
        respond_fail(401, 'Bad signature');
    }

    $uploadFile = null;
    if (!empty($_FILES['archive']) && !empty($_FILES['archive']['tmp_name']) && is_uploaded_file($_FILES['archive']['tmp_name'])) {
        $uploadFile = $_FILES['archive'];
    } elseif (!empty($_FILES['file']) && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $uploadFile = $_FILES['file'];
    }

    if (!$uploadFile) {
        respond_fail(422, 'Missing archive or file');
    }

    $root = rtrim(str_replace('\\', '/', dirname(__FILE__)), '/');
    $chunkIndex = isset($_POST['chunkIndex']) ? (int) $_POST['chunkIndex'] : null;
    $totalChunks = isset($_POST['totalChunks']) ? (int) $_POST['totalChunks'] : 1;

    // Direct raw file upload (single file or chunked)
    if (str_starts_with($target, 'raw/')) {
        $dest = $root . '/' . $target;
        if ($chunkIndex !== null && $totalChunks > 1) {
            $tmpDir = $root . '/.tmp_chunks/' . md5($target);
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0755, true);
            }
            $part = $tmpDir . '/' . $chunkIndex . '.part';
            if (!move_uploaded_file($uploadFile['tmp_name'], $part)) {
                respond_fail(500, 'Cannot save raw chunk');
            }
            $parts = glob($tmpDir . '/*.part') ?: [];
            if (count($parts) >= $totalChunks) {
                // All parts received, assemble!
                $pDir = dirname($dest);
                if (!is_dir($pDir)) {
                    @mkdir($pDir, 0755, true);
                }
                $out = fopen($dest, 'wb');
                if ($out === false) {
                    respond_fail(500, 'Cannot open destination file');
                }
                for ($i = 0; $i < $totalChunks; $i++) {
                    $p = $tmpDir . '/' . $i . '.part';
                    if (!is_file($p)) {
                        fclose($out);
                        respond_fail(422, "Missing chunk {$i}");
                    }
                    $in = fopen($p, 'rb');
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    @unlink($p);
                }
                fclose($out);
                @rmdir($tmpDir);

                echo json_encode([
                    'ok' => true,
                    'complete' => true,
                    'target' => $target,
                    'size' => filesize($dest),
                ]);
                exit;
            }

            echo json_encode([
                'ok' => true,
                'complete' => false,
                'received' => count($parts),
                'total' => $totalChunks,
            ]);
            exit;
        }

        // Single file raw upload
        $pDir = dirname($dest);
        if (!is_dir($pDir)) {
            @mkdir($pDir, 0755, true);
        }
        if (!move_uploaded_file($uploadFile['tmp_name'], $dest)) {
            respond_fail(500, 'Cannot save raw file');
        }

        echo json_encode([
            'ok' => true,
            'complete' => true,
            'target' => $target,
            'size' => filesize($dest),
        ]);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        respond_fail(500, 'ZipArchive not available on PHP host');
    }

    $tmpZip = $uploadFile['tmp_name'];

    // Handle chunked bundle zip assembly
    if ($chunkIndex !== null && $totalChunks > 1) {
        $tmpDir = $root . '/.tmp_chunks/bundle_' . md5($target . '_' . $ts);
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $part = $tmpDir . '/' . $chunkIndex . '.part';
        if (!move_uploaded_file($uploadFile['tmp_name'], $part)) {
            respond_fail(500, 'Cannot save bundle chunk');
        }
        $parts = glob($tmpDir . '/*.part') ?: [];
        if (count($parts) < $totalChunks) {
            echo json_encode([
                'ok' => true,
                'complete' => false,
                'received' => count($parts),
                'total' => $totalChunks,
            ]);
            exit;
        }

        // Assemble parts into full zip
        $assembledZip = $tmpDir . '/bundle.zip';
        $out = fopen($assembledZip, 'wb');
        if ($out === false) {
            respond_fail(500, 'Cannot assemble bundle zip');
        }
        for ($i = 0; $i < $totalChunks; $i++) {
            $p = $tmpDir . '/' . $i . '.part';
            if (!is_file($p)) {
                fclose($out);
                respond_fail(422, "Missing bundle chunk {$i}");
            }
            $in = fopen($p, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            @unlink($p);
        }
        fclose($out);
        $tmpZip = $assembledZip;
    }

    // Case A: Full bundle unpacking (from local-video-processor or LocalBundleIngestor)
    if ($isBundle) {
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            respond_fail(500, 'Cannot open zip');
        }

        $manifestIdx = $zip->locateName('manifest.json');
        if ($manifestIdx === false) {
            $zip->close();
            respond_fail(422, 'Missing manifest.json in bundle');
        }
        $manifestJson = $zip->getFromIndex($manifestIdx);
        $manifest = json_decode((string) $manifestJson, true);
        $remotePaths = (array) ($manifest['remote_paths'] ?? []);

        $fileCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/') || $name === 'manifest.json' || str_contains($name, '..')) {
                continue;
            }

            $destRel = null;
            if (str_starts_with($name, 'stream/') && isset($remotePaths['stream'])) {
                $destRel = trim((string) $remotePaths['stream'], '/') . '/' . substr($name, 7);
            } elseif (str_starts_with($name, 'download/') && isset($remotePaths['download'])) {
                $destRel = trim((string) $remotePaths['download'], '/') . '/' . substr($name, 9);
            } elseif (str_starts_with($name, 'trailer/') && isset($remotePaths['trailer'])) {
                $destRel = trim((string) $remotePaths['trailer'], '/') . '/' . substr($name, 8);
            }

            if ($destRel !== null) {
                $destPath = $root . '/' . $destRel;
                $pDir = dirname($destPath);
                if (!is_dir($pDir)) {
                    @mkdir($pDir, 0755, true);
                }
                $stream = $zip->getStream($name);
                if ($stream) {
                    $out = fopen($destPath, 'wb');
                    if ($out) {
                        stream_copy_to_stream($stream, $out);
                        fclose($out);
                        $fileCount++;
                    }
                    fclose($stream);
                }
            }
        }
        $zip->close();

        echo json_encode([
            'ok' => true,
            'target' => 'bundle',
            'files' => $fileCount,
            'has_playlist' => true,
        ]);
        exit;
    }

    // Case B: Single target unpacking (from worker)
    $dest = $root . '/' . $target;

    if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
        respond_fail(500, 'Cannot create target directory');
    }

    if (is_dir($dest)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        respond_fail(500, 'Cannot open zip');
    }
    if (!$zip->extractTo($dest)) {
        $zip->close();
        respond_fail(500, 'Extract failed');
    }
    $zip->close();

    $hasPlaylist = is_file($dest . '/playlist.m3u8') || is_file($dest . '/index.m3u8');
    $fileCount = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $fileCount++;
        }
    }

    echo json_encode([
        'ok' => true,
        'target' => $target,
        'files' => $fileCount,
        'has_playlist' => $hasPlaylist,
    ]);
    exit;
}

// =========================================================================
// 3. GET / HEAD (Secure Signed Video Streaming & File Delivery)
// =========================================================================

// Determine request parameters
$token = (string) ($_GET['token'] ?? '');
$expires = (string) ($_GET['expires'] ?? '');
$path = (string) ($_GET['path'] ?? '');

// Parse from clean URL if routed via .htaccess /media-stream/{token}/{expires}/{path}
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($token === '' && preg_match('#^/media-stream/([a-fA-F0-9]{32,64})/([0-9]+)/(.+)$#', $uri, $matches)) {
    $token = $matches[1];
    $expires = $matches[2];
    $path = $matches[3];
}

// If direct unauthenticated access to protected folders was intercepted by .htaccess
if (!empty($_GET['blocked'])) {
    respond_fail(403, 'Forbidden: Direct access without valid signature is denied.');
}

if ($path === '') {
    // If accessing root "/" without params, output simple identifier
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Zanburak Storage Gateway Active';
    exit;
}

// Sanitize path
$path = str_replace('\\', '/', $path);
$path = ltrim($path, '/');

if (
    $path === ''
    || str_contains($path, '..')
    || str_contains($path, "\0")
    || preg_match('#(^|/)\.#', $path) // deny hidden files (.env, .htaccess, etc.)
) {
    respond_fail(404, 'Invalid media path');
}

// Check expiration
if ($expires === '' || !ctype_digit($expires)) {
    respond_fail(403, 'Missing or invalid expiration timestamp');
}

if (time() > (int) $expires) {
    respond_fail(403, 'Media link has expired');
}

// Verify HMAC token
if ($token === '') {
    respond_fail(403, 'Missing security token');
}

// 1. Directory-level verification (for HLS stream: playlist, child playlists, ts segments, keys)
$dir = rtrim(dirname($path), '/');
if (str_ends_with($dir, '/keys')) {
    $dir = substr($dir, 0, -5);
}
if ($dir === '.' || $dir === '') {
    $dir = $path;
}

$expectedDir32 = substr(hash_hmac('sha256', "{$dir}|{$expires}", $secret), 0, 32);
$expectedDir64 = hash_hmac('sha256', "{$dir}|{$expires}", $secret);

// 2. File-level verification (for single-file downloads like MP4)
$expectedFile32 = substr(hash_hmac('sha256', "{$path}|{$expires}", $secret), 0, 32);
$expectedFile64 = hash_hmac('sha256', "{$path}|{$expires}", $secret);

$valid = hash_equals($expectedDir32, $token)
    || hash_equals($expectedDir64, $token)
    || hash_equals($expectedFile32, $token)
    || hash_equals($expectedFile64, $token);

if (!$valid) {
    respond_fail(403, 'Forbidden: Invalid token signature');
}

// Origin & Referer Anti-Hotlinking verification
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '') {
    $refHost = parse_url($referer, PHP_URL_HOST);
    if ($refHost) {
        $allowed = false;
        foreach ($ALLOWED_ORIGIN_PATTERNS as $pattern) {
            if (preg_match($pattern, 'http://' . $refHost) || preg_match($pattern, 'https://' . $refHost)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            respond_fail(403, 'Forbidden: Hotlinking from unauthorized domain');
        }
    }
}

// Locate file on disk
$root = rtrim(str_replace('\\', '/', dirname(__FILE__)), '/');
$filePath = $root . '/' . $path;

if (!is_file($filePath)) {
    // If an encryption key was referenced directly in playlist without 'keys/' prefix
    if (str_ends_with(strtolower($path), '.key')) {
        $altKeyPath = $root . '/' . dirname($path) . '/keys/' . basename($path);
        if (is_file($altKeyPath)) {
            $filePath = $altKeyPath;
        }
    }
}

if (!is_file($filePath)) {
    respond_fail(404, 'File not found');
}

// Determine Content-Type and Cache-Control
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeMap = [
    'm3u8' => 'application/vnd.apple.mpegurl; charset=utf-8',
    'ts'   => 'video/mp2t',
    'key'  => 'application/octet-stream',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mkv'  => 'video/x-matroska',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'vtt'  => 'text/vtt; charset=utf-8',
];

$contentType = $mimeMap[$ext] ?? 'application/octet-stream';
$fileSize = filesize($filePath);

header('Content-Type: ' . $contentType);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');

if (in_array($ext, ['ts', 'mp4', 'webm', 'jpg', 'jpeg', 'png', 'gif'], true)) {
    header('Cache-Control: public, max-age=86400, immutable');
} else {
    // Playlists and keys should not be cached aggressively
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Download filename / Content-Disposition
if (!empty($_GET['dl']) || isset($_GET['filename'])) {
    $downloadName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) ($_GET['filename'] ?? basename($filePath)));
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
} else {
    header('Content-Disposition: inline');
}

// If HEAD request, exit early after sending headers
if ($requestMethod === 'HEAD') {
    header('Content-Length: ' . $fileSize);
    exit;
}

// Clean any previous output buffer to avoid corruption
while (ob_get_level() > 0) {
    ob_end_clean();
}

// =========================================================================
// Byte-Range Support (HTTP 206 Partial Content) for Video Seeking & Scrubbing
// =========================================================================
$start = 0;
$end = $fileSize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('#bytes=([0-9]+)-([0-9]*)#', $_SERVER['HTTP_RANGE'], $rangeMatches)) {
        $start = (int) $rangeMatches[1];
        if (!empty($rangeMatches[2])) {
            $end = (int) $rangeMatches[2];
        }

        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            http_response_code(416); // Range Not Satisfiable
            header("Content-Range: bytes */{$fileSize}");
            exit;
        }

        $length = $end - $start + 1;
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
        header("Content-Length: {$length}");
    } else {
        http_response_code(416);
        header("Content-Range: bytes */{$fileSize}");
        exit;
    }
} else {
    http_response_code(200);
    header("Content-Length: {$fileSize}");
    $length = $fileSize;
}

// Stream file in 64KB chunks
$fp = @fopen($filePath, 'rb');
if (!$fp) {
    respond_fail(500, 'Unable to open file for streaming');
}

if ($start > 0) {
    fseek($fp, $start);
}

$chunkSize = 64 * 1024;
$bytesRemaining = $length;

while (!feof($fp) && $bytesRemaining > 0 && connection_status() === CONNECTION_NORMAL) {
    $readSize = min($chunkSize, $bytesRemaining);
    $data = fread($fp, $readSize);
    if ($data === false) {
        break;
    }
    echo $data;
    flush();
    $bytesRemaining -= strlen($data);
}

fclose($fp);
exit;
