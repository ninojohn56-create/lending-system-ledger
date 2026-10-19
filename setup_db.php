<?php
/**
 * One-Click Database Setup & Migrations Runner
 * Accessible via CLI (php setup_db.php) or Browser (/setup_db.php)
 */

require_once __DIR__ . '/config.php';

$message = '';
$status = 'info';
$output = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || (php_sapi_name() === 'cli')) {
    $dbHost = $_POST['db_host'] ?? DB_HOST;
    $dbPort = $_POST['db_port'] ?? DB_PORT;
    $dbName = !empty($_POST['db_name']) ? trim($_POST['db_name']) : DB_NAME;
    $dbUser = isset($_POST['db_user']) ? trim($_POST['db_user']) : DB_USER;
    $dbPass = isset($_POST['db_pass']) ? $_POST['db_pass'] : DB_PASS;

    try {
        // 1. Read schema.sql
        $schemaFile = __DIR__ . '/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception("schema.sql file not found at " . $schemaFile);
        }
        $rawSql = file_get_contents($schemaFile);

        // Replace database name in schema with the target database
        $sql = preg_replace('/`lending_db`/', "`{$dbName}`", $rawSql);

        // Try connecting directly or via host
        $pdo = null;
        $connectErrors = [];

        // Attempt list: direct target DB or server root
        $attempts = [
            // Connect to server root first to CREATE DATABASE
            "mysql:host={$dbHost};port={$dbPort};charset=" . DB_CHARSET,
            // Fallback for Linux socket
            "mysql:host=localhost;charset=" . DB_CHARSET,
            // Connect directly to database if already created
            "mysql:host={$dbHost};dbname={$dbName};charset=" . DB_CHARSET,
            "mysql:host=localhost;dbname={$dbName};charset=" . DB_CHARSET
        ];

        foreach ($attempts as $dsn) {
            try {
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
                break;
            } catch (PDOException $ex) {
                $connectErrors[] = $ex->getMessage();
            }
        }

        if (!$pdo) {
            throw new Exception("Could not connect to MySQL server. Errors: " . implode(" | ", array_unique($connectErrors)));
        }

        $output[] = "Connected successfully to MySQL server.";

        // Ensure database exists
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
            $output[] = "Database `{$dbName}` selected/created.";
        } catch (PDOException $e) {
            // If CREATE DATABASE permission is restricted, try USE directly
            $pdo->exec("USE `{$dbName}`");
            $output[] = "Using existing database `{$dbName}`.";
        }

        // Execute table definitions and seeds
        $pdo->exec($sql);
        $output[] = "Schema tables and initial seed records executed.";

        // Verify table row counts
        $tables = ['borrowers', 'loan_plans', 'loans', 'repayments', 'audit_logs'];
        foreach ($tables as $t) {
            $count = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $output[] = "Table `{$t}`: {$count} records verified.";
        }

        $message = "Database `{$dbName}` initialized successfully! All tables and seed data are ready.";
        $status = 'success';

        if (php_sapi_name() === 'cli') {
            echo implode(PHP_EOL, $output) . PHP_EOL . "SUCCESS: {$message}\n";
            exit(0);
        }
    } catch (Exception $e) {
        $message = "Database Setup Failed: " . $e->getMessage();
        $status = 'danger';
        $output[] = "Error details: " . $e->getMessage();

        if (php_sapi_name() === 'cli') {
            echo implode(PHP_EOL, $output) . PHP_EOL . "ERROR: {$message}\n";
            exit(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup & Reset - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">

<div class="container py-5" style="max-width: 720px;">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white py-3">
            <h4 class="mb-0 fw-bold"><i class="bi bi-database-gear me-2"></i> Database Initialization & Migration Tool</h4>
        </div>
        <div class="card-body p-4">
            <p class="text-muted">
                This utility initializes the MySQL/MariaDB database, builds the 5 core tables (Borrowers, Loan Plans, Loans, Repayments, Audit Logs), sets foreign key constraints, and seeds demo records.
            </p>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $status ?> d-flex align-items-center mb-4">
                    <i class="bi <?= ($status === 'success') ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-4 me-2"></i>
                    <div>
                        <strong><?= ($status === 'success') ? 'Success!' : 'Notice' ?></strong> <?= htmlspecialchars($message) ?>
                    </div>
                </div>

                <?php if (!empty($output)): ?>
                    <div class="bg-dark text-light p-3 rounded-3 mb-4 font-monospace small">
                        <?php foreach ($output as $line): ?>
                            <div>&gt; <?= htmlspecialchars($line) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($status === 'success'): ?>
                    <a href="index.php" class="btn btn-success w-100 py-2 fw-bold mb-3">
                        <i class="bi bi-speedometer2 me-1"></i> Launch Dashboard
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" action="setup_db.php">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">MySQL Host</label>
                        <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars(DB_HOST) ?>" required>
                        <small class="text-muted">Use <code>localhost</code> for Linux socket or <code>127.0.0.1</code> for TCP.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Port</label>
                        <input type="text" name="db_port" class="form-control" value="<?= htmlspecialchars(DB_PORT) ?>" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Database Name</label>
                        <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars(DB_NAME) ?>" required>
                        <small class="text-muted">Default is <code>test_lending</code> (which works without root permissions on MariaDB).</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">MySQL Username</label>
                        <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars(DB_USER) ?>">
                        <small class="text-muted">Defaults to current Linux user <code><?= htmlspecialchars(getenv('USER') ?: '') ?></code>.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">MySQL Password</label>
                        <input type="password" name="db_pass" class="form-control" placeholder="Leave blank if no password" value="<?= htmlspecialchars(DB_PASS) ?>">
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Return to Dashboard
                    </a>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">
                        <i class="bi bi-lightning-charge-fill me-1"></i> Initialize Database Now
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
