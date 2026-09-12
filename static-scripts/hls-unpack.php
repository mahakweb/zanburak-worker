<?php
/**
 * Deploy once to the STATIC host document root, e.g.:
 *   https://static.zanburak.ir/hls-unpack.php
 *
 * Receives a single ZIP of HLS/download outputs and extracts it under the web root.
 *
 * Env / config: set the same secret as worker STATIC_DEPLOY_SECRET.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/**
 * NOTE: On this static host, newly uploaded .php files are NOT executed
 * (source is served instead). Prefer static-scripts/index-deploy.php which
 * overwrites the already-executable public_html/index.php.
 */
const DEPLOY_SECRET = 'uftj30zp4skhe6b7ngl18qymiv9rcxao5wd2';
const MAX_SKEW_SECONDS = 600;

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'POST only');
}

$target = (string) ($_POST['target'] ?? '');
$ts = (string) ($_POST['ts'] ?? '');
$sig = (string) ($_POST['sig'] ?? '');
$target = str_replace('\\', '/', $target);
$target = trim($target, '/');

if ($target === '' || str_contains($target, '..') || !preg_match('#^(stream|download|trailer|raw)/[A-Za-z0-9._/-]+$#', $target)) {
    fail(422, 'Invalid target');
}

if ($ts === '' || $sig === '' || !ctype_digit($ts)) {
    fail(401, 'Missing signature');
}

if (abs(time() - (int) $ts) > MAX_SKEW_SECONDS) {
    fail(401, 'Signature expired');
}

$secret = getenv('STATIC_DEPLOY_SECRET') ?: DEPLOY_SECRET;
if ($secret === '' || str_starts_with($secret, 'CHANGE_ME')) {
    fail(500, 'Deploy secret not configured on static host');
}

$expected = hash_hmac('sha256', $ts . "\n" . $target, $secret);
if (!hash_equals($expected, $sig)) {
    fail(401, 'Bad signature');
}

if (empty($_FILES['archive']) || !is_uploaded_file($_FILES['archive']['tmp_name'] ?? '')) {
    fail(422, 'Missing archive');
}

$tmpZip = $_FILES['archive']['tmp_name'];
$root = rtrim(str_replace('\\', '/', __DIR__), '/');
$dest = $root . '/' . $target;

if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
    fail(500, 'Cannot create target directory');
}

// Clear previous files in target (HLS replace).
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}

$zip = new ZipArchive();
if ($zip->open($tmpZip) !== true) {
    fail(500, 'Cannot open zip');
}

if (!$zip->extractTo($dest)) {
    $zip->close();
    fail(500, 'Extract failed');
}
$zip->close();

// Basic sanity: playlist should exist for stream/trailer.
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
], JSON_UNESCAPED_UNICODE);
