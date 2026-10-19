<?php
/**
 * Staff Authentication & Safe First-Time Administrator Setup
 * File: login.php
 *
 * Implements:
 * - System Function #1: User login (using PDO prepared statements & password_verify())
 * - Safe initial administrator creation (using password_hash()) ONLY when the users table is empty
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

// If already logged in, redirect directly to the dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . baseUrl('dashboard.php'));
    exit;
}

// Check how many staff accounts exist in the database
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

$errors   = [];
$username = '';
$fullName = 'System Administrator';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? 'login';

        // --------------------------------------------------------------------
        // SAFE INITIAL ADMIN SETUP (Only allowed when no users exist yet)
        // --------------------------------------------------------------------
        if ($action === 'setup_admin') {
            if ($userCount > 0) {
                $errors[] = 'An administrator account already exists. Please sign in below.';
            } else {
                $quickDemo = isset($_POST['quick_demo']) && $_POST['quick_demo'] === '1';

                if ($quickDemo) {
                    $username        = 'admin';
                    $fullName        = 'System Administrator';
                    $password        = 'Admin@1234';
                    $confirmPassword = 'Admin@1234';
                } else {
                    $username        = trim((string)($_POST['username'] ?? ''));
                    $fullName        = trim((string)($_POST['full_name'] ?? ''));
                    $password        = (string)($_POST['password'] ?? '');
                    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
                }

                if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
                    $errors[] = 'Username must be 3 to 30 characters and contain only letters, numbers, dots, underscores, or hyphens.';
                }
                if ($fullName === '' || mb_strlen($fullName) > 100) {
                    $errors[] = 'Please provide a valid full name (up to 100 characters).';
                }
                if ( strlen($password) < 6) {
                    $errors[] = 'Password must be at least 6 characters long.';
                }
                if ($password !== $confirmPassword) {
                    $errors[] = 'Password and confirmation password do not match.';
                }

                if (empty($errors)) {
                    // Hash password securely using bcrypt (never store plaintext passwords)
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare(
                        'INSERT INTO users (username, full_name, password_hash)
                         VALUES (:username, :full_name, :password_hash)'
                    );
                    $stmt->execute([
                        ':username'      => $username,
                        ':full_name'     => $fullName,
                        ':password_hash' => $passwordHash,
                    ]);

                    $newUserId = (int)$pdo->lastInsertId();

                    // Also seed the initial sample payments for Loan #1 and Loan #2 now that a valid staff user exists
                    $paymentCount  = (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
                    $loanOneExists = (int)$pdo->query('SELECT COUNT(*) FROM loans WHERE id = 1')->fetchColumn();
                    $loanTwoExists = (int)$pdo->query('SELECT COUNT(*) FROM loans WHERE id = 2')->fetchColumn();
                    if ($paymentCount === 0 && $loanOneExists > 0) {
                        $seedPayment = $pdo->prepare(
                            'INSERT INTO payments (loan_id, amount, payment_date, payment_method, reference_no, recorded_by, remarks)
                             VALUES (:loan_id, :amount, :payment_date, :method, :ref, :recorded_by, :remarks)'
                        );
                        $seedPayment->execute([
                            ':loan_id'      => 1,
                            ':amount'       => 2200.00,
                            ':payment_date' => '2026-10-01',
                            ':method'       => 'Cash',
                            ':ref'          => 'OR-2026-001',
                            ':recorded_by'  => $newUserId,
                            ':remarks'      => 'Initial partial installment payment for LN-2026-001',
                        ]);
                        if ($loanTwoExists > 0) {
                            $seedPayment->execute([
                                ':loan_id'      => 2,
                                ':amount'       => 28000.00,
                                ':payment_date' => '2026-09-10',
                                ':method'       => 'Bank Transfer',
                                ':ref'          => 'OR-2026-002',
                                ':recorded_by'  => $newUserId,
                                ':remarks'      => 'Full settlement payment for LN-2026-002',
                            ]);
                        }
                    }

                    // Log the newly created administrator in immediately
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $newUserId;
                    $_SESSION['username']  = $username;
                    $_SESSION['full_name'] = $fullName;

                    setFlash(
                        'success',
                        'Initial administrator account (' . $username . ') created and signed in successfully!'
                    );
                    header('Location: ' . baseUrl('dashboard.php'));
                    exit;
                }
            }
        }

        // --------------------------------------------------------------------
        // STANDARD STAFF LOGIN
        // --------------------------------------------------------------------
        if ($action === 'login') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($username === '' || $password === '') {
                $errors[] = 'Please enter both your username and password.';
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id, username, full_name, password_hash
                     FROM users
                     WHERE username = :username
                     LIMIT 1'
                );
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($password, $user['password_hash'])) {
                    $errors[] = 'Invalid username or password. Please try again.';
                } else {
                    // Prevent session fixation by regenerating the session ID upon login
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = (int)$user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];

                    setFlash('success', 'Welcome back, ' . $user['full_name'] . '!');
                    header('Location: ' . baseUrl('dashboard.php'));
                    exit;
                }
            }
        }
    }
}

$pageTitle  = 'Staff Login';
$hideLayout = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-5 px-3" style="background-color: var(--surface-bg);">
    <div style="width: 100%; max-width: 460px;">
        <!-- Brand Header -->
        <div class="text-center mb-4">
            <div class="bg-primary text-white rounded-3 d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 52px; height: 52px; font-size: 1.5rem;">
                <i class="bi bi-bank2"></i>
            </div>
            <h3 class="fw-bold mb-1"><?= e(APP_NAME) ?></h3>
            <p class="text-secondary small mb-0">Authorized Staff Portal &bull; Loan &amp; Repayment Management</p>
        </div>

        <?php
        $flash = getFlash();
        if ($flash !== null):
        ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm mb-3" role="alert">
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm mb-3" role="alert">
                <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Authentication Notice</div>
                <ul class="mb-0 ps-3 small">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($userCount === 0): ?>
            <!-- FIRST-TIME INITIAL ADMIN SETUP CARD -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <span class="badge bg-warning text-dark me-1">First-Time Setup</span>
                    <span class="fw-bold">Create Initial Administrator</span>
                </div>
                <div class="card-body p-4">
                    <p class="text-secondary small mb-3">
                        No staff accounts exist in the <code>users</code> table yet. Create your initial administrator account below (passwords are hashed using PHP <code>password_hash()</code>).
                    </p>

                    <!-- One-Click Demo Admin Setup for School Defense / Testing -->
                    <form method="POST" action="<?= e(baseUrl('login.php')) ?>" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="setup_admin">
                        <input type="hidden" name="quick_demo" value="1">
                        <button type="submit" class="btn btn-outline-primary w-100 btn-sm py-2">
                            <i class="bi bi-lightning-charge-fill me-1"></i>Quick Setup Default Admin (<code>admin</code> / <code>Admin@1234</code>)
                        </button>
                    </form>

                    <div class="text-center text-muted small my-2">&mdash; or configure custom credentials &mdash;</div>

                    <form method="POST" action="<?= e(baseUrl('login.php')) ?>" novalidate>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="setup_admin">

                        <div class="mb-3">
                            <label for="setup_full_name" class="form-label">Administrator Full Name</label>
                            <input type="text" class="form-control" id="setup_full_name" name="full_name" value="<?= e($fullName) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="setup_username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="setup_username" name="username" value="<?= e($username ?: 'admin') ?>" placeholder="e.g. admin" required>
                        </div>

                        <div class="mb-3">
                            <label for="setup_password" class="form-label">Password (min. 6 characters)</label>
                            <input type="password" class="form-control" id="setup_password" name="password" required>
                        </div>

                        <div class="mb-4">
                            <label for="setup_confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="setup_confirm_password" name="confirm_password" required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <i class="bi bi-shield-check me-1"></i>Create Administrator &amp; Sign In
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- STANDARD STAFF LOGIN CARD -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <i class="bi bi-shield-lock text-primary me-1"></i>
                    <span>Sign In to Your Account</span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="<?= e(baseUrl('login.php')) ?>" novalidate>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="login">

                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-secondary"><i class="bi bi-person"></i></span>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="username"
                                    name="username"
                                    value="<?= e($username) ?>"
                                    placeholder="Enter your staff username"
                                    autocomplete="username"
                                    autofocus
                                    required
                                >
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-secondary"><i class="bi bi-key"></i></span>
                                <input
                                    type="password"
                                    class="form-control"
                                    id="password"
                                    name="password"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                    required
                                >
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="text-center text-muted small mt-4">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &bull; Philippine Peso (<?= e(APP_CURRENCY) ?>)
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
