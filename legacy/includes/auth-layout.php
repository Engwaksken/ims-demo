<?php
declare(strict_types=1);

/*
| Shared shell for the signed-out account pages (forgot / reset password):
| the same branded card as login.php, themed from Settings > General.
*/

function auth_layout_start(string $title, string $subtitle): void
{
    $brand = ims_brand();
    $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $faviconQuery = [];
    parse_str((string)(parse_url($brand['favicon'], PHP_URL_QUERY) ?? ''), $faviconQuery);
    $faviconFile = (string)($faviconQuery['file'] ?? $brand['favicon']);
    $faviconType = strtolower(pathinfo($faviconFile, PATHINFO_EXTENSION)) === 'ico' ? 'image/x-icon' : 'image/png';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $h($title) ?> - <?= $h($brand['name']) ?></title>
    <link rel="icon" href="<?= $h($brand['favicon']) ?>" type="<?= $faviconType ?>">
    <link rel="stylesheet" href="css/style.css">
    <?= ims_theme_style_tag($brand['theme']) ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="js/accessibility.js"></script>
    <style>
        .auth-intro { color: var(--ink-500, #374151); font-size: 14px; margin: 0 0 20px; text-align: center; }
        .auth-links { margin-top: 20px; text-align: center; font-size: 14px; }
        .auth-links a { color: var(--brand-700); font-weight: 500; }
        .auth-hint { display: block; margin-top: 6px; color: #7f8c8d; font-size: 12px; }
    </style>
</head>
<body>
    <main class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>
                    <img src="<?= $h($brand['logo']) ?>" alt="<?= $h($brand['name']) ?> Logo" onerror="this.style.display='none'">
                </h1>
                <p><?= $h($subtitle) ?></p>
            </div>
    <?php
}

function auth_layout_end(): void
{
    ?>
        </div>
    </main>
</body>
</html>
    <?php
}

/** An alert block for the auth card; $type is danger, success or info. */
function auth_alert(string $type, string $message): string
{
    $icon = ['danger' => 'fa-exclamation-circle', 'success' => 'fa-check-circle', 'info' => 'fa-info-circle'][$type] ?? 'fa-info-circle';
    return '<div class="alert alert-' . $type . '" role="' . ($type === 'danger' ? 'alert' : 'status') . '">'
        . '<i class="fas ' . $icon . '" aria-hidden="true"></i> <span>'
        . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></div>';
}
