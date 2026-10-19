<?php
/**
 * Shared Top Navigation Bar
 * File: includes/navbar.php
 */
$loggedInUser = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Staff');
?>
<nav class="navbar navbar-expand-lg app-navbar px-3 px-lg-4 sticky-top no-print">
    <div class="container-fluid px-0">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(baseUrl('dashboard.php')) ?>">
            <span class="bg-primary text-white rounded-2 d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <i class="bi bi-bank2"></i>
            </span>
            <span><?= e(APP_NAME) ?></span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="d-none d-lg-flex align-items-center ms-auto gap-3">
            <span class="text-secondary small">
                <i class="bi bi-calendar3 me-1"></i><?= e(date('F j, Y')) ?>
            </span>
            <div class="vr text-muted"></div>
            <span class="text-dark fw-medium small">
                <i class="bi bi-person-circle text-primary me-1"></i><?= e($loggedInUser) ?>
            </span>
            <a href="<?= e(baseUrl('logout.php')) ?>" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>
