<?php
declare(strict_types=1);

$errors = [
    400 => ['Bad Request','The request could not be understood by the server.'],
    401 => ['Authentication Required','Please sign in to continue.'],
    403 => ['Access Denied','You do not have permission to access this page.'],
    404 => ['Page Not Found','The page or resource you requested could not be found.'],
    405 => ['Method Not Allowed','This request method is not supported for this page.'],
    408 => ['Request Timeout','The request took too long to complete. Please try again.'],
    429 => ['Too Many Requests','Please wait briefly and try again.'],
    500 => ['Server Error','Something went wrong while processing your request.'],
    502 => ['Bad Gateway','The server received an invalid response from an upstream service.'],
    503 => ['Service Unavailable','The service is temporarily unavailable. Please try again shortly.'],
    504 => ['Gateway Timeout','The server took too long to receive a response.'],
];

$code = (int)($_GET['code'] ?? 404);
if (!isset($errors[$code])) $code = 404;

http_response_code($code);
[$title,$message] = $errors[$code];

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<?php $appName = (string)(getenv('APP_NAME') ?: ($_ENV['APP_NAME'] ?? 'Information Management System')); ?>
<title><?= $code ?> - <?= e($title) ?> | <?= e($appName) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#f6f7f9;font-family:Arial,sans-serif;color:#111827}
.card{width:min(650px,100%);padding:38px;background:#fff;border:1px solid #e5e7eb;border-radius:22px;text-align:center;box-shadow:0 20px 55px rgba(0,0,0,.09)}
.logo{display:block;width:auto;max-width:170px;height:42px;object-fit:contain;margin:0 auto 25px}
.code{color:#f97316;font-size:13px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
h1{margin:8px 0 10px;font-size:clamp(26px,5vw,38px)}
p{color:#6b7280;line-height:1.7}
.actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:25px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 18px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;color:#111827;font-weight:700;text-decoration:none}
.btn.primary{border-color:#f97316;background:#f97316;color:#fff}
@media(max-width:520px){.card{padding:28px 18px}.actions{flex-direction:column}.btn{width:100%}}
</style>
</head>
<body>
<main class="card">
<img src="/images/logo.png" class="logo" alt="<?= e($appName) ?>">
<div class="code">Error <?= $code ?></div>
<h1><?= e($title) ?></h1>
<p><?= e($message) ?></p>
<div class="actions">
<a href="/login" class="btn primary">Go to Login</a>
<a href="javascript:history.back()" class="btn">Go Back</a>
</div>
</main>
</body>
</html>
