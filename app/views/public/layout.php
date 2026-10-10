<?php /** The bare public layout (the customer's door now; slice 6 reuses it for /s/): no shell, no menu, no script. Data: title, content, settings (business_name) */
$biz = (string) ($settings['business_name'] ?? app_name());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? $biz) ?> · <?= e($biz) ?></title>
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css">
</head>
<body class="bg-light">
    <main class="public-order" id="public-order">
        <?= $content ?>
    </main>
</body>
</html>
