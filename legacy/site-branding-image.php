<?php
declare(strict_types=1);

// Serve only generated, allow-listed branding image names from the public directory.
$filename = (string)($_GET['file'] ?? '');
if (!preg_match('/\A[a-f0-9]{32}\.(?:png|jpe?g|webp|ico)\z/i', $filename)) {
    http_response_code(404);
    exit;
}

$path = dirname(__DIR__) . '/public/uploads/site-branding/' . $filename;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$contentType = match ($extension) {
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $contentType);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: public, max-age=86400, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
