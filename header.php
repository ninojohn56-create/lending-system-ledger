<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
$flash = getFlash();
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Loan Management & Tracking</title>
    
    <!-- Prevent White Flash: Read and apply saved theme immediately -->
    <script>
        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons 1.11.3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --bs-primary: #2563eb;
            --bs-primary-rgb: 37, 99, 235;
            --font-family-sans-serif: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        }
        body {
            font-family: var(--font-family-sans-serif);
            background-color: var(--bs-body-bg);
            color: var(--bs-body-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .navbar-nav .nav-link {
            font-weight: 500;
            padding: 0.6rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        .navbar-nav .nav-link.active,
        .navbar-nav .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.15);
            color: #fff;
        }
        .card {
            border: 1px solid var(--bs-border-color);
            border-radius: 0.85rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }
        .card-stat {
            position: relative;
            overflow: hidden;
        }
        .card-stat .stat-icon {
            position: absolute;
            right: 1.25rem;
            bottom: 1rem;
            font-size: 3rem;
            opacity: 0.15;
        }
        .badge {
            font-weight: 600;
            padding: 0.45em 0.75em;
            letter-spacing: 0.2px;
        }
        .badge-status-pending { background-color: #fef3c7; color: #b45309; }
        .badge-status-approved { background-color: #e0f2fe; color: #0369a1; }
        .badge-status-disbursed { background-color: #dcfce7; color: #15803d; }
        .badge-status-completed { background-color: #f1f5f9; color: #475569; }
        .badge-status-defaulted { background-color: #fee2e2; color: #b91c1c; }
        .badge-status-rejected { background-color: #f3f4f6; color: #6b7280; }

        .btn {
            font-weight: 500;
            border-radius: 0.5rem;
        }
        .content-wrapper {
            flex: 1 0 auto;
        }
        footer {
            flex-shrink: 0;
            background: var(--bs-body-bg);
            border-top: 1px solid var(--bs-border-color);
        }

        /* Dark Mode Specific Overrides */
        [data-bs-theme="dark"] body {
            background-color: #000000ff;
            color: #cbd5e1;
        }
        [data-bs-theme="dark"] .bg-white {
            background-color: #1e293b !important;
            color: #f8fafc !important;
        }
        [data-bs-theme="dark"] .card-header.bg-white {
            background-color: #000001ff !important;
            border-color: #334155 !important;
        }
        [data-bs-theme="dark"] .bg-light {
            background-color: #0f172a !important;
            color: #e2e8f0 !important;
        }
        [data-bs-theme="dark"] .text-dark {
            color: #f8fafc !important;
        }
        [data-bs-theme="dark"] .table {
            --bs-table-bg: transparent;
            --bs-table-striped-bg: rgba(255, 255, 255, 0.02);
            --bs-table-hover-bg: rgba(255, 255, 255, 0.05);
            color: #e2e8f0;
        }
        [data-bs-theme="dark"] .table thead th {
            background-color: #131c2e !important;
            color: #94a3b8;
            border-color: #334155;
        }
        [data-bs-theme="dark"] .modal-content {
            background-color: #1e293b;
            border-color: #334155;
            color: #f8fafc;
        }
        [data-bs-theme="dark"] .modal-footer.bg-light {
            background-color: #0f172a !important;
            border-color: #334155;
        }
        [data-bs-theme="dark"] .form-control,
        [data-bs-theme="dark"] .form-select {
            background-color: #0f172a;
            border-color: #334155;
            color: #f8fafc;
        }
        [data-bs-theme="dark"] .form-control:focus,
        [data-bs-theme="dark"] .form-select:focus {
            background-color: #0f172a;
            border-color: #3b82f6;
            color: #f8fafc;
        }
        [data-bs-theme="dark"] .input-group-text {
            background-color: #131c2e !important;
            border-color: #334155;
            color: #cbd5e1;
        }
        [data-bs-theme="dark"] .list-group-item {
            background-color: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        [data-bs-theme="dark"] .btn-light {
            background-color: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
        }
        [data-bs-theme="dark"] .btn-light:hover {
            background-color: #334155;
            color: #f8fafc;
        }
    </style>
</head>
<body>

<!-- Top Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm py-2">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <span class="badge bg-primary p-2 rounded-3">
                <i class="bi bi-wallet2 fs-5"></i>
            </span>
            <span><?= APP_NAME ?></span>
        </a>
        
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3 gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'index.php') ? 'active' : '' ?>" href="index.php">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'borrowers.php' || $currentPage === 'borrower_view.php') ? 'active' : '' ?>" href="borrowers.php">
                        <i class="bi bi-people me-1"></i> Borrowers
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'loans.php' || $currentPage === 'loan_view.php') ? 'active' : '' ?>" href="loans.php">
                        <i class="bi bi-cash-stack me-1"></i> Loans
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'payments.php') ? 'active' : '' ?>" href="payments.php">
                        <i class="bi bi-receipt me-1"></i> Repayments
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'plans.php') ? 'active' : '' ?>" href="plans.php">
                        <i class="bi bi-gear-wide-connected me-1"></i> Plans
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'audit_logs.php') ? 'active' : '' ?>" href="audit_logs.php">
                        <i class="bi bi-shield-check me-1"></i> Activity Log
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <!-- Dark Mode Toggle Button -->
                <button class="btn btn-sm btn-outline-light d-flex align-items-center gap-2 px-3 py-1" id="themeToggleBtn" type="button" title="Toggle Dark/Light Mode">
                    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
                    <span id="themeText" class="small fw-semibold">Dark</span>
                </button>
            </div>
        </div>
    </div>
</nav>

<!-- Main Container -->
<div class="content-wrapper container-fluid px-lg-4 py-4">
    <!-- Flash Messages Component -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show d-flex align-items-center shadow-sm" role="alert">
            <i class="bi <?= ($flash['type'] === 'success') ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-4 me-2"></i>
            <div><?= htmlspecialchars($flash['message']) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
