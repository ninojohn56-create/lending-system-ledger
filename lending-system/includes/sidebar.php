<?php
/**
 * Shared Sidebar Navigation
 * File: includes/sidebar.php
 *
 * Main Navigation Items (per Section 8):
 * 1. Dashboard
 * 2. Borrowers
 * 3. Loans
 * 4. Payments
 * 5. Reports
 * 6. Logout
 */
$activeMenu = $activeMenu ?? 'dashboard';
?>
<aside class="app-sidebar collapse d-lg-block no-print" id="sidebarMenu">
    <div class="p-3">
        <div class="text-uppercase text-muted fw-semibold px-3 mb-2" style="font-size: 0.72rem; letter-spacing: 0.06em;">
            Main Navigation
        </div>
        <nav class="nav flex-column sidebar-nav">
            <a class="nav-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>" href="<?= e(baseUrl('dashboard.php')) ?>">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
            <a class="nav-link <?= $activeMenu === 'borrowers' ? 'active' : '' ?>" href="<?= e(baseUrl('borrowers/index.php')) ?>">
                <i class="bi bi-people"></i>
                <span>Borrowers</span>
            </a>
            <a class="nav-link <?= $activeMenu === 'loans' ? 'active' : '' ?>" href="<?= e(baseUrl('loans/index.php')) ?>">
                <i class="bi bi-journal-text"></i>
                <span>Loans</span>
            </a>
            <a class="nav-link <?= $activeMenu === 'payments' ? 'active' : '' ?>" href="<?= e(baseUrl('payments/index.php')) ?>">
                <i class="bi bi-cash-coin"></i>
                <span>Payments</span>
            </a>
            <a class="nav-link <?= $activeMenu === 'reports' ? 'active' : '' ?>" href="<?= e(baseUrl('reports/index.php')) ?>">
                <i class="bi bi-bar-chart-line"></i>
                <span>Reports</span>
            </a>

            <hr class="my-2 text-muted opacity-25">

            <a class="nav-link text-danger" href="<?= e(baseUrl('logout.php')) ?>">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </nav>
    </div>
</aside>
