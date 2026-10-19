<?php
/**
 * Staff Logout Handler
 * File: logout.php
 *
 * Implements System Function #2: User logout
 * Safely clears session data, invalidates the session cookie, and redirects to login.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

// Clear all session variables
$_SESSION = [];

// Delete the session cookie from the browser if cookies are used
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        (bool)$params['secure'],
        (bool)$params['httponly']
    );
}

// Destroy the current server-side session
session_destroy();

// Start a fresh session solely to display the logout confirmation flash alert
session_start();
setFlash('info', 'You have been logged out safely.');

header('Location: ' . baseUrl('login.php'));
exit;
