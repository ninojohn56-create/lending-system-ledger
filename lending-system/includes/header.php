<?php
/**
 * Shared HTML Document Header
 * File: includes/header.php
 *
 * Expects $pageTitle (string) and $activeMenu (string) to be set before inclusion.
 */
$pageTitle  = $pageTitle ?? APP_NAME;
$activeMenu = $activeMenu ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom System Styles -->
    <link href="<?= e(baseUrl('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<?php if (empty($hideLayout)): ?>
    <?php require __DIR__ . '/navbar.php'; ?>
    <div class="app-wrapper">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <main class="app-main">
            <?php
            // Display one-time session flash message if one exists
            $flash = getFlash();
            if ($flash !== null):
            ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm mb-4 no-print" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
<?php endif; ?>
