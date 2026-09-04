<?php
/**
 * R2 媒体代理 - 私有桶通过此接口访问图片/视频
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Security;
use App\Services\R2StorageService;

if (ob_get_level()) {
    ob_end_clean();
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$keyParam = $_GET['k'] ?? '';
if ($keyParam === '') {
    http_response_code(400);
    exit('Bad Request');
}

$objectKey = base64_decode($keyParam, true);
if ($objectKey === false || $objectKey === '') {
    http_response_code(400);
    exit('Invalid key');
}

$r2 = new R2StorageService();
if (!$r2->isEnabled() || !$r2->isR2Key($objectKey)) {
    http_response_code(403);
    exit('Forbidden');
}

$filename = Security::sanitizeFilename((string) ($_GET['f'] ?? basename($objectKey)));
if ($filename === 'file' || $filename === '') {
    $filename = basename($objectKey) ?: 'media.bin';
}

$download = !empty($_GET['dl']);

if ($method === 'HEAD') {
    $meta = $r2->headObject($objectKey);
    if ($meta === null) {
        http_response_code(404);
        exit;
    }

    $contentType = $meta['content_type'];
    if ($contentType === '' || $contentType === 'application/octet-stream') {
        $contentType = R2StorageService::guessMimeFromKey($objectKey);
    }

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    if (!empty($meta['content_length'])) {
        header('Content-Length: ' . $meta['content_length']);
    }
    header('Cache-Control: public, max-age=86400');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    http_response_code(200);
    exit;
}

$range = $_SERVER['HTTP_RANGE'] ?? null;

if (!$r2->streamObject($objectKey, $range, [
    'filename' => $filename,
    'download' => $download,
])) {
    if (!headers_sent()) {
        http_response_code(404);
    }
}

exit;
