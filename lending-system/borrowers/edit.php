<?php
/**
 * Edit Borrower Information
 * File: borrowers/edit.php
 *
 * Implements System Function #6: Edit borrower information
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    setFlash('danger', 'Invalid borrower ID specified.');
    header('Location: ' . baseUrl('borrowers/index.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM borrowers WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$borrower = $stmt->fetch();

if (!$borrower) {
    setFlash('danger', 'Borrower record not found.');
    header('Location: ' . baseUrl('borrowers/index.php'));
    exit;
}

$errors     = [];
$borrowerNo = $borrower['borrower_no'];
$fullName   = $borrower['full_name'];
$phone      = $borrower['phone'];
$address    = $borrower['address'];
$isArchived = (int)$borrower['is_archived'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid CSRF token. Please refresh the page and try again.';
    } else {
        $borrowerNo = strtoupper(trim((string)($_POST['borrower_no'] ?? '')));
        $fullName   = trim((string)($_POST['full_name'] ?? ''));
        $phone      = trim((string)($_POST['phone'] ?? ''));
        $address    = trim((string)($_POST['address'] ?? ''));
        $isArchived = isset($_POST['is_archived']) && $_POST['is_archived'] === '1' ? 1 : 0;

        if ($borrowerNo === '' || !preg_match('/^[A-Z0-9-]{4,30}$/', $borrowerNo)) {
            $errors[] = 'Borrower No. must be 4–30 characters (uppercase letters, numbers, and hyphens).';
        }
        if ($fullName === '' || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
            $errors[] = 'Full Name must be between 2 and 120 characters.';
        }
        if ($phone === '' || !preg_match('/^[0-9+() -]{7,30}$/', $phone)) {
            $errors[] = 'Please enter a valid contact phone number (7–30 digits).';
        }
        if ($address === '' || mb_strlen($address) < 5) {
            $errors[] = 'Please enter a valid complete address.';
        }

        // Prevent archiving if borrower still has open unpaid loans
        if ($isArchived === 1) {
            $openCheck = $pdo->prepare(
                "SELECT COUNT(*) FROM loans
                 WHERE borrower_id = :id
                   AND status IN ('pending', 'active', 'partially_paid', 'overdue')
                   AND balance > 0"
            );
            $openCheck->execute([':id' => $id]);
            if ((int)$openCheck->fetchColumn() > 0) {
                $errors[] = 'Cannot mark borrower as Archived while they still have an unpaid open loan.';
                $isArchived = 0;
            }
        }

        // Check duplicate borrower_no excluding current borrower
        if (empty($errors)) {
            $dupStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM borrowers WHERE borrower_no = :borrower_no AND id != :id'
            );
            $dupStmt->execute([
                ':borrower_no' => $borrowerNo,
                ':id'          => $id,
            ]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $errors[] = 'Borrower No. "' . $borrowerNo . '" is already used by another borrower.';
            }
        }

        if (empty($errors)) {
            $updateStmt = $pdo->prepare(
                'UPDATE borrowers
                 SET borrower_no = :borrower_no,
                     full_name   = :full_name,
                     phone       = :phone,
                     address     = :address,
                     is_archived = :is_archived
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':borrower_no' => $borrowerNo,
                ':full_name'   => $fullName,
                ':phone'       => $phone,
                ':address'     => $address,
                ':is_archived' => $isArchived,
                ':id'          => $id,
            ]);

            setFlash('success', 'Borrower "' . $fullName . '" updated successfully.');
            header('Location: ' . baseUrl('borrowers/index.php?view_id=' . $id));
            exit;
        }
    }
}

$pageTitle  = 'Edit Borrower';
$activeMenu = 'borrowers';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('dashboard.php')) ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('borrowers/index.php')) ?>">Borrowers</a></li>
        <li class="breadcrumb-item active" aria-current="page">Edit <?= e($borrower['borrower_no']) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">Edit Borrower Record</h3>
        <p class="text-secondary mb-0">Updating profile for <strong><?= e($borrower['full_name']) ?></strong> (<?= e($borrower['borrower_no']) ?>).</p>
    </div>
    <a href="<?= e(baseUrl('borrowers/index.php?view_id=' . $id)) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Profile
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pencil-square text-primary me-2"></i>Borrower Details
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

                <form method="POST" action="<?= e(baseUrl('borrowers/edit.php?id=' . $id)) ?>" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">

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
                        </div>

                        <div class="col-md-7">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                id="full_name"
                                name="full_name"
                                value="<?= e($fullName) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label for="phone" class="form-label">Contact Phone Number <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                id="phone"
                                name="phone"
                                value="<?= e($phone) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-5">
                            <label for="is_archived" class="form-label">Account Standing</label>
                            <select class="form-select" id="is_archived" name="is_archived">
                                <option value="0" <?= $isArchived === 0 ? 'selected' : '' ?>>Active Borrower</option>
                                <option value="1" <?= $isArchived === 1 ? 'selected' : '' ?>>Archived (Inactive)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="address" class="form-label">Complete Address <span class="text-danger">*</span></label>
                        <textarea
                            class="form-control"
                            id="address"
                            name="address"
                            rows="3"
                            required
                        ><?= e($address) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-save me-1"></i>Save Changes
                        </button>
                        <a href="<?= e(baseUrl('borrowers/index.php?view_id=' . $id)) ?>" class="btn btn-light border">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
