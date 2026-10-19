<?php
/**
 * Application Entry Router
 * File: index.php
 *
 * Redirects authenticated staff to dashboard.php and unauthenticated visitors to login.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . baseUrl('dashboard.php'));
    exit;
}

header('Location: ' . baseUrl('login.php'));
exit;
