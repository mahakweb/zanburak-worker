<?php
/**
 * static.zanburak.ir entrypoint.
 * GET  → plain "static" (legacy).
 * POST → signed ZIP unpack (same contract as hls-unpack.php).
 *
 * Secret must match worker STATIC_DEPLOY_SECRET.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: origin, x-requested-with, content-type');
header('Access-Control-Allow-Methods: PUT, GET, POST, DELETE, OPTIONS');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'static';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$DEPLOY_SECRET = 'uftj30zp4skhe6b7ngl18qymiv9rcxao5wd2';
$MAX_SKEW = 600;

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

function deploy_fail($code, $message)
{
    http_response_code($code);
    echo json_encode(array('ok' => false, 'message' => $message));
    exit;
}

$target = isset($_POST['target']) ? (string) $_POST['target'] : '';
$ts = isset($_POST['ts']) ? (string) $_POST['ts'] : '';
$sig = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
$target = str_replace('\\', '/', $target);
$target = trim($target, '/');

if ($target === '' || strpos($target, '..') !== false || !preg_match('#^(stream|download|trailer|raw)/[A-Za-z0-9._/-]+$#', $target)) {
    deploy_fail(422, 'Invalid target');
}

if ($ts === '' || $sig === '' || !ctype_digit($ts)) {
    deploy_fail(401, 'Missing signature');
}

if (abs(time() - (int) $ts) > $MAX_SKEW) {
    deploy_fail(401, 'Signature expired');
}

$secret = getenv('STATIC_DEPLOY_SECRET');
if (!$secret) {
    $secret = $DEPLOY_SECRET;
}
if ($secret === '' || strpos($secret, 'CHANGEME') === 0) {
    deploy_fail(500, 'Deploy secret not configured on static host');
}

$expected = hash_hmac('sha256', $ts . "\n" . $target, $secret);
if (!hash_equals($expected, $sig)) {
    deploy_fail(401, 'Bad signature');
}

if (empty($_FILES['archive']) || empty($_FILES['archive']['tmp_name']) || !is_uploaded_file($_FILES['archive']['tmp_name'])) {
    deploy_fail(422, 'Missing archive');
}

if (!class_exists('ZipArchive')) {
    deploy_fail(500, 'ZipArchive not available');
}

$tmpZip = $_FILES['archive']['tmp_name'];
$root = rtrim(str_replace('\\', '/', dirname(__FILE__)), '/');
$dest = $root . '/' . $target;

if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
    deploy_fail(500, 'Cannot create target directory');
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
    deploy_fail(500, 'Cannot open zip');
}
if (!$zip->extractTo($dest)) {
    $zip->close();
    deploy_fail(500, 'Extract failed');
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

echo json_encode(array(
    'ok' => true,
    'target' => $target,
    'files' => $fileCount,
    'has_playlist' => $hasPlaylist,
));
