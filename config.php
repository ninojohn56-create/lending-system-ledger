<?php
/**
 * Lending Tracking System - Database Configuration
 * Clean & Simple setup for ITC Project
 */

// Start session for alerts and security
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'test_lending');
define('DB_USER', getenv('USER') ?: '');
define('DB_PASS', '');

// Connect to MySQL using PDO
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Function to get the PDO instance
function getDbConnection() {
    global $pdo;
    return $pdo;
}

// System Constants
const APP_CURRENCY = '₱';
const APP_NAME     = 'QuickLend Tracking System';

// Format number to currency (e.g. ₱ 1,500.00)
function formatCurrency($amount) {
    return APP_CURRENCY . ' ' . number_format((float)$amount, 2);
}

// Format date to human readable (e.g. Oct 18, 2026)
function formatDate($date) {
    return $date ? date('M d, Y', strtotime($date)) : 'N/A';
}

// Flash Message Helpers (Alerts)
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

// CSRF Security Token Helpers
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
