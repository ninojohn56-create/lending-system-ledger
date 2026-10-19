<?php
/**
 * Add New Borrower Form & Server-Side Handler
 * File: borrowers/add.php
 *
 * Implements System Function #4: Add borrower
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();

$errors     = [];
$borrowerNo = generateNextCode($pdo, 'borrowers', 'borrower_no', 'BRW', 3);
$fullName   = '';
$phone      = '';
$address    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid CSRF token. Please refresh the page and try again.';
    } else {
        $borrowerNo = strtoupper(trim((string)($_POST['borrower_no'] ?? '')));
        $fullName   = trim((string)($_POST['full_name'] ?? ''));
        $phone      = trim((string)($_POST['phone'] ?? ''));
        $address    = trim((string)($_POST['address'] ?? ''));

        // Server-side validation
        if ($borrowerNo === '' || !preg_match('/^[A-Z0-9-]{4,30}$/', $borrowerNo)) {
            $errors[] = 'Borrower No. is required (4–30 characters using uppercase letters, digits, and hyphens).';
        }
        if ($fullName === '' || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
            $errors[] = 'Full Name is required and must be between 2 and 120 characters.';
        }
        if ($phone === '' || !preg_match('/^[0-9+() -]{7,30}$/', $phone)) {
            $errors[] = 'Please enter a valid contact phone number (7–30 digits).';
        }
        if ($address === '' || mb_strlen($address) < 5) {
            $errors[] = 'Please provide a complete residential or business address.';
        }

        // Check duplicate borrower_no
        if (empty($errors)) {
            $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM borrowers WHERE borrower_no = :borrower_no');
            $dupStmt->execute([':borrower_no' => $borrowerNo]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $errors[] = 'Borrower No. "' . $borrowerNo . '" is already assigned to another client.';
            }
        }

        if (empty($errors)) {
            $insertStmt = $pdo->prepare(
                'INSERT INTO borrowers (borrower_no, full_name, phone, address, is_archived)
                 VALUES (:borrower_no, :full_name, :phone, :address, 0)'
            );
            $insertStmt->execute([
                ':borrower_no' => $borrowerNo,
                ':full_name'   => $fullName,
                ':phone'       => $phone,
                ':address'     => $address,
            ]);

            $newBorrowerId = (int)$pdo->lastInsertId();
            setFlash('success', 'Borrower "' . $fullName . '" (' . $borrowerNo . ') has been registered successfully.');
            header('Location: ' . baseUrl('borrowers/index.php?view_id=' . $newBorrowerId));
            exit;
        }
    }
}

$pageTitle  = 'Add Borrower';
$activeMenu = 'borrowers';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Breadcrumb & Header -->
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('dashboard.php')) ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('borrowers/index.php')) ?>">Borrowers</a></li>
        <li class="breadcrumb-item active" aria-current="page">Add Borrower</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">Register New Borrower</h3>
        <p class="text-secondary mb-0">A borrower profile is required before creating any loan agreement.</p>
    </div>
    <a href="<?= e(baseUrl('borrowers/index.php')) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Borrowers
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-person-Vcard text-primary me-2"></i>Client Registration Form
            </div>
            <div class="card-body p-4">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-circle-fill me-1"></i>Please fix the following errors:</div>
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= e(baseUrl('borrowers/add.php')) ?>" novalidate>
                    <?= csrfField() ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label for="borrower_no" class="form-label">Borrower No. <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                id="borrower_no"
                                name="borrower_no"
                                value="<?= e($borrowerNo) ?>"
                                required
                            >
                            <div class="form-text">Unique client identifier (auto-generated).</div>
                        </div>

                        <div class="col-md-7">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                id="full_name"
                                name="full_name"
                                value="<?= e($fullName) ?>"
                                placeholder="e.g. Pedro Penduko"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Contact Phone Number <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="phone"
                            name="phone"
                            value="<?= e($phone) ?>"
                            placeholder="e.g. 0917-555-0148"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label for="address" class="form-label">Complete Address <span class="text-danger">*</span></label>
                        <textarea
                            class="form-control"
                            id="address"
                            name="address"
                            rows="3"
                            placeholder="House/Unit No., Street, Barangay, City/Municipality, Province"
                            required
                        ><?= e($address) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check2-circle me-1"></i>Save Borrower Record
                        </button>
                        <a href="<?= e(baseUrl('borrowers/index.php')) ?>" class="btn btn-light border">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mt-4 mt-lg-0">
        <div class="card bg-light border-0">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-2"><i class="bi bi-info-circle text-primary me-1"></i>Registration Guidelines</h6>
                <ul class="small text-secondary ps-3 mb-0">
                    <li class="mb-2">Every borrower receives a unique <strong>Borrower No.</strong> enforced by a MySQL <code>UNIQUE</code> constraint.</li>
                    <li class="mb-2">Verify the client's phone number and current address before issuing a loan.</li>
                    <li>After saving, you will be taken directly to the borrower's profile where you can create a new loan agreement.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
