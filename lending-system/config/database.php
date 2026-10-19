<?php
/**
 * Database Connection & Core Application Helpers
 * File: config/database.php
 *
 * Uses PDO (PHP Data Objects) with prepared statements and strict error handling.
 */

declare(strict_types=1);

// Start session for authentication and flash messages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set default timezone to Manila, Philippines
date_default_timezone_set('Asia/Manila');

// Database configuration constants
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'test_lending_system');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : (getenv('USER') ?: 'root'));
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// Application constants
define('APP_NAME', 'LendCore Management System');
define('APP_CURRENCY', '₱');

/**
 * Connect to MySQL/MariaDB using PDO.
 */
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $pdoOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on SQL errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return rows as associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Use real MySQL prepared statements
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
    // Log detailed technical error privately on the server; show safe message to user
    error_log('Database Connection Error: ' . $e->getMessage());
    http_response_code(500);
    die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Database Error</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body class="bg-light d-flex align-items-center justify-content-center vh-100">'
        . '<div class="card shadow-sm border-0 p-4" style="max-width: 520px;">'
        . '<h4 class="text-danger mb-2">Database Connection Unavailable</h4>'
        . '<p class="text-muted mb-3">The system could not connect to the MySQL database. '
        . 'Please ensure MySQL/MariaDB is running and <code>database/lending_system.sql</code> has been imported.</p>'
        . '</div></body></html>');
}

/**
 * Build a clean URL relative to the lending-system root folder.
 * Works whether hosted at the server root or inside a subfolder.
 */
function baseUrl(string $path = ''): string
{
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
    $docRoot     = isset($_SERVER['DOCUMENT_ROOT'])
        ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: '')
        : '';

    $prefix = '';
    if ($docRoot !== '' && str_starts_with($projectRoot, $docRoot)) {
        $prefix = substr($projectRoot, strlen($docRoot));
    }

    $prefix = rtrim($prefix, '/');
    $path   = ltrim($path, '/');

    return $path === '' ? ($prefix ?: '/') : $prefix . '/' . $path;
}

/**
 * Escape output safely for HTML to prevent Cross-Site Scripting (XSS).
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format a numeric value as Philippine Peso (₱).
 */
function formatPeso(float|int|string|null $amount): string
{
    return APP_CURRENCY . number_format((float)($amount ?? 0), 2);
}

/**
 * Generate or return the current session CSRF token.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token from a POST form.
 */
function verifyCsrfToken(?string $submittedToken): bool
{
    if (empty($_SESSION['csrf_token']) || empty($submittedToken)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submittedToken);
}

/**
 * Store a one-time flash alert message in the session.
 * Types: 'success', 'danger', 'warning', 'info'
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash_message'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Retrieve and clear the flash alert message from the session.
 */
function getFlash(): ?array
{
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
    return $flash;
}

/**
 * Output a hidden HTML input field containing the CSRF token.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/**
 * Enforce user authentication on protected pages.
 */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        setFlash('warning', 'Please log in to access the system.');
        header('Location: ' . baseUrl('login.php'));
        exit;
    }
}

/**
 * Calculate Flat Interest Loan breakdown (Section 7 Formula).
 *
 * Formula:
 *   Total Interest = Principal × (Monthly Interest Rate / 100) × Number of Months
 *   Total Payable  = Principal + Total Interest
 *   Monthly Due    = Total Payable / Number of Months
 */
function calculateFlatLoan(float $principal, float $monthlyRatePercent, int $termMonths): array
{
    $totalInterest = round($principal * ($monthlyRatePercent / 100) * $termMonths, 2);
    $totalPayable  = round($principal + $totalInterest, 2);
    $monthlyDue    = $termMonths > 0 ? round($totalPayable / $termMonths, 2) : 0.00;

    return [
        'principal'      => round($principal, 2),
        'interest_rate'  => round($monthlyRatePercent, 2),
        'term_months'    => $termMonths,
        'total_interest' => $totalInterest,
        'total_payable'  => $totalPayable,
        'monthly_due'    => $monthlyDue,
    ];
}

/**
 * Render a semantic status badge for a loan status.
 */
function loanStatusBadge(string $status): string
{
    $labels = [
        'pending'        => 'Pending',
        'active'         => 'Active',
        'partially_paid' => 'Partially Paid',
        'paid'           => 'Paid',
        'overdue'        => 'Overdue',
        'rejected'       => 'Rejected',
        'cancelled'      => 'Cancelled',
    ];
    $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    $class = 'badge-' . preg_replace('/[^a-z0-9_]/', '', strtolower($status));

    return '<span class="badge-status ' . e($class) . '">' . e($label) . '</span>';
}

/**
 * Automatically update loans whose maturity due_date has passed and still carry a balance.
 */
function syncOverdueLoans(PDO $pdo): void
{
    $sql = "UPDATE loans
            SET status = 'overdue'
            WHERE status IN ('active', 'partially_paid')
              AND balance > 0
              AND due_date < CURDATE()";
    $pdo->exec($sql);
}

/**
 * Generate the next sequential reference code (e.g. BRW-2026-005, LN-2026-004, OR-2026-002).
 */
function generateNextCode(PDO $pdo, string $table, string $column, string $prefix, int $padLength = 3): string
{
    $allowedTables = [
        'borrowers' => 'borrower_no',
        'loans'     => 'loan_no',
        'payments'  => 'reference_no',
    ];
    if (!isset($allowedTables[$table]) || $allowedTables[$table] !== $column) {
        throw new InvalidArgumentException('Invalid table or column for code generation.');
    }

    $year = date('Y');
    $stmt = $pdo->query("SELECT MAX(id) FROM {$table}");
    $nextNum = ((int)$stmt->fetchColumn()) + 1;

    do {
        $candidate = sprintf('%s-%s-%0' . $padLength . 'd', $prefix, $year, $nextNum);
        $check = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :code");
        $check->execute([':code' => $candidate]);
        $exists = ((int)$check->fetchColumn()) > 0;
        if ($exists) {
            $nextNum++;
        }
    } while ($exists);

    return $candidate;
}

