<?php
/**
 * Create Loan Agreement & Server-Side Flat Interest Calculator
 * File: loans/add.php
 *
 * Implements:
 * - System Function #9: Create loan records
 * - Section 7 Flat Interest Formula:
 *     Total Interest = Principal × (Monthly Interest Rate / 100) × Number of Months
 *     Total Payable  = Principal + Total Interest
 * - Strict server-side validation (never trusts browser calculations)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();

// Fetch all active (non-archived) borrowers for the selection dropdown
$borrowersStmt = $pdo->query(
    'SELECT id, borrower_no, full_name, phone
     FROM borrowers
     WHERE is_archived = 0
     ORDER BY full_name ASC'
);
$activeBorrowers = $borrowersStmt->fetchAll();

$errors         = [];
$loanNo         = generateNextCode($pdo, 'loans', 'loan_no', 'LN', 3);
$borrowerId     = isset($_GET['borrower_id']) ? (int)$_GET['borrower_id'] : 0;
$principal      = '10000.00';
$interestRate   = '2.00';
$interestMethod = 'flat';
$termMonths     = '6';
$releaseDate    = date('Y-m-d');
$initialStatus  = 'active';
$remarks        = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $loanNo         = strtoupper(trim((string)($_POST['loan_no'] ?? '')));
        $borrowerId     = (int)($_POST['borrower_id'] ?? 0);
        $principal      = trim((string)($_POST['principal'] ?? ''));
        $interestRate   = trim((string)($_POST['interest_rate'] ?? ''));
        $interestMethod = trim((string)($_POST['interest_method'] ?? 'flat'));
        $termMonths     = trim((string)($_POST['term_months'] ?? ''));
        $releaseDate    = trim((string)($_POST['release_date'] ?? ''));
        $initialStatus  = trim((string)($_POST['status'] ?? 'active'));
        $remarks        = trim((string)($_POST['remarks'] ?? ''));

        // 1. Validate Loan No.
        if ($loanNo === '' || !preg_match('/^[A-Z0-9-]{4,30}$/', $loanNo)) {
            $errors[] = 'Loan No. is required (4–30 characters using uppercase letters, digits, and hyphens).';
        }

        // 2. Validate existing active borrower (Section 5: Do not allow a loan without an existing borrower)
        if ($borrowerId <= 0) {
            $errors[] = 'Please select an existing registered borrower.';
        } else {
            $bCheck = $pdo->prepare('SELECT id, is_archived FROM borrowers WHERE id = :id LIMIT 1');
            $bCheck->execute([':id' => $borrowerId]);
            $bRow = $bCheck->fetch();
            if (!$bRow) {
                $errors[] = 'The selected borrower does not exist in the database.';
            } elseif ((int)$bRow['is_archived'] === 1) {
                $errors[] = 'Cannot create a new loan for an archived borrower.';
            }
        }

        // 3. Validate Principal Amount
        if (!is_numeric($principal) || (float)$principal <= 0 || (float)$principal > 100000000) {
            $errors[] = 'Principal amount must be a positive number greater than ₱0.00.';
        }

        // 4. Validate Monthly Interest Rate
        if (!is_numeric($interestRate) || (float)$interestRate < 0 || (float)$interestRate > 100) {
            $errors[] = 'Monthly interest rate must be between 0% and 100%.';
        }

        // 5. Validate Interest Method (strictly flat interest as required)
        if ($interestMethod !== 'flat') {
            $errors[] = 'Only the Flat Interest calculation method is supported.';
        }

        // 6. Validate Loan Term (Months)
        if (!ctype_digit($termMonths) || (int)$termMonths < 1 || (int)$termMonths > 120) {
            $errors[] = 'Loan term must be a whole number of months between 1 and 120.';
        }

        // 7. Validate Release Date
        $releaseTimestamp = strtotime($releaseDate);
        if ($releaseDate === '' || $releaseTimestamp === false) {
            $errors[] = 'Please provide a valid loan release date.';
        }

        // 8. Validate Initial Status
        if (!in_array($initialStatus, ['active', 'pending'], true)) {
            $errors[] = 'Initial loan status must be either Active or Pending Approval.';
        }

        // 9. Check Duplicate Loan No.
        if (empty($errors)) {
            $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE loan_no = :loan_no');
            $dupStmt->execute([':loan_no' => $loanNo]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $errors[] = 'Loan No. "' . $loanNo . '" already exists. Please use a unique Loan No.';
            }
        }

        // 10. Server-Side Calculation & Database Insert
        if (empty($errors)) {
            $calc = calculateFlatLoan((float)$principal, (float)$interestRate, (int)$termMonths);

            // Compute final maturity date by adding term_months to release_date
            $dueDateObj = new DateTimeImmutable($releaseDate);
            $dueDate    = $dueDateObj->modify('+' . (int)$termMonths . ' months')->format('Y-m-d');

            // If a loan is active and its maturity date is already in the past, mark as overdue
            $finalStatus = $initialStatus;
            if ($finalStatus === 'active' && $dueDate < date('Y-m-d')) {
                $finalStatus = 'overdue';
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO loans (
                    loan_no, borrower_id, principal, interest_rate, interest_method,
                    term_months, total_interest, total_payable, monthly_due, balance,
                    release_date, due_date, status, remarks
                 ) VALUES (
                    :loan_no, :borrower_id, :principal, :interest_rate, :interest_method,
                    :term_months, :total_interest, :total_payable, :monthly_due, :balance,
                    :release_date, :due_date, :status, :remarks
                 )'
            );

            $insertStmt->execute([
                ':loan_no'         => $loanNo,
                ':borrower_id'     => $borrowerId,
                ':principal'       => $calc['principal'],
                ':interest_rate'   => $calc['interest_rate'],
                ':interest_method' => 'flat',
                ':term_months'     => $calc['term_months'],
                ':total_interest'  => $calc['total_interest'],
                ':total_payable'   => $calc['total_payable'],
                ':monthly_due'     => $calc['monthly_due'],
                ':balance'         => $calc['total_payable'],
                ':release_date'    => $releaseDate,
                ':due_date'        => $dueDate,
                ':status'          => $finalStatus,
                ':remarks'         => $remarks !== '' ? $remarks : null,
            ]);

            $newLoanId = (int)$pdo->lastInsertId();
            setFlash(
                'success',
                'Loan ' . $loanNo . ' created! Scheduled Total Payable: ' . formatPeso($calc['total_payable']) . '.'
            );
            header('Location: ' . baseUrl('loans/view.php?id=' . $newLoanId));
            exit;
        }
    }
}

// Compute initial preview values on the server for display
$preview = calculateFlatLoan(
    is_numeric($principal) ? (float)$principal : 0.0,
    is_numeric($interestRate) ? (float)$interestRate : 0.0,
    ctype_digit((string)$termMonths) ? (int)$termMonths : 1
);

$pageTitle  = 'Create Loan';
$activeMenu = 'loans';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('dashboard.php')) ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('loans/index.php')) ?>">Loans</a></li>
        <li class="breadcrumb-item active" aria-current="page">Create Loan</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">Create New Loan Record</h3>
        <p class="text-secondary mb-0">Issue a loan agreement linked to a registered borrower with automatic flat-interest calculation.</p>
    </div>
    <a href="<?= e(baseUrl('loans/index.php')) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Loans
    </a>
</div>

<?php if (empty($activeBorrowers)): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between">
        <div>
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>No active borrowers found!</strong> You must register a borrower before creating a loan record.
        </div>
        <a href="<?= e(baseUrl('borrowers/add.php')) ?>" class="btn btn-sm btn-primary">Register Borrower Now</a>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left Column: Loan Input Form -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-file-earmark-plus text-primary me-2"></i>Loan Agreement Terms
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

                <form method="POST" action="<?= e(baseUrl('loans/add.php')) ?>" id="loanForm" novalidate>
                    <?= csrfField() ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label for="loan_no" class="form-label">Loan No. <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                id="loan_no"
                                name="loan_no"
                                value="<?= e($loanNo) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-7">
                            <label for="borrower_id" class="form-label">Select Borrower <span class="text-danger">*</span></label>
                            <select class="form-select" id="borrower_id" name="borrower_id" required>
                                <option value="">-- Choose Registered Borrower --</option>
                                <?php foreach ($activeBorrowers as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>" <?= $borrowerId === (int)$b['id'] ? 'selected' : '' ?>>
                                        <?= e($b['full_name']) ?> (<?= e($b['borrower_no']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="principal" class="form-label">Principal (<?= e(APP_CURRENCY) ?>) <span class="text-danger">*</span></label>
                            <input
                                type="number"
                                step="0.01"
                                min="1"
                                class="form-control"
                                id="principal"
                                name="principal"
                                value="<?= e($principal) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-4">
                            <label for="interest_rate" class="form-label">Monthly Rate (%) <span class="text-danger">*</span></label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                class="form-control"
                                id="interest_rate"
                                name="interest_rate"
                                value="<?= e($interestRate) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-4">
                            <label for="term_months" class="form-label">Term (Months) <span class="text-danger">*</span></label>
                            <input
                                type="number"
                                step="1"
                                min="1"
                                max="120"
                                class="form-control"
                                id="term_months"
                                name="term_months"
                                value="<?= e($termMonths) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="interest_method" class="form-label">Interest Method</label>
                            <select class="form-select bg-light" id="interest_method" name="interest_method">
                                <option value="flat" selected>Flat Interest (Monthly)</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="release_date" class="form-label">Release Date <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control"
                                id="release_date"
                                name="release_date"
                                value="<?= e($releaseDate) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-4">
                            <label for="status" class="form-label">Initial Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" <?= $initialStatus === 'active' ? 'selected' : '' ?>>Active (Disbursed)</option>
                                <option value="pending" <?= $initialStatus === 'pending' ? 'selected' : '' ?>>Pending Approval</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="remarks" class="form-label">Loan Purpose / Remarks</label>
                        <input
                            type="text"
                            class="form-control"
                            id="remarks"
                            name="remarks"
                            value="<?= e($remarks) ?>"
                            placeholder="Optional notes (e.g., Small business capital, Tuition assistance)"
                        >
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4" <?= empty($activeBorrowers) ? 'disabled' : '' ?>>
                            <i class="bi bi-check2-circle me-1"></i>Create Loan Agreement
                        </button>
                        <a href="<?= e(baseUrl('loans/index.php')) ?>" class="btn btn-light border">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Live Repayment Schedule Preview -->
    <div class="col-lg-5">
        <div class="card border-primary shadow-sm">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-calculator me-2"></i>Calculated Repayment Preview
            </div>
            <div class="card-body p-4">
                <p class="text-secondary small mb-3">
                    Live preview using the <strong>Flat Interest Formula</strong>. Final values are strictly re-verified on the server when saved.
                </p>

                <div class="bg-light rounded-3 p-3 border mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Loan Principal:</span>
                        <span class="fw-semibold" id="prevPrincipal"><?= e(formatPeso($preview['principal'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Monthly Rate &times; Term:</span>
                        <span class="fw-medium" id="prevRateTerm"><?= e($preview['interest_rate']) ?>% &times; <?= (int)$preview['term_months'] ?> mos</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Total Flat Interest:</span>
                        <span class="fw-semibold text-warning" id="prevInterest"><?= e(formatPeso($preview['total_interest'])) ?></span>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-dark">Total Scheduled Repayment:</span>
                        <span class="fs-5 fw-bold text-primary" id="prevTotalPayable"><?= e(formatPeso($preview['total_payable'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary small">Estimated Monthly Installment:</span>
                        <span class="fw-semibold text-success small" id="prevMonthlyDue"><?= e(formatPeso($preview['monthly_due'])) ?> / month</span>
                    </div>
                </div>

                <div class="small text-secondary">
                    <div class="fw-bold text-dark mb-1"><i class="bi bi-book me-1"></i>How the Formula Works:</div>
                    <div class="mb-1"><code>Total Interest = Principal &times; Monthly Rate &times; Months</code></div>
                    <div><code>Total Payable = Principal + Total Interest</code></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Client-side helper to update the repayment preview card as the user types
(function () {
    const principalInput = document.getElementById('principal');
    const rateInput      = document.getElementById('interest_rate');
    const termInput      = document.getElementById('term_months');

    const prevPrincipal    = document.getElementById('prevPrincipal');
    const prevRateTerm     = document.getElementById('prevRateTerm');
    const prevInterest     = document.getElementById('prevInterest');
    const prevTotalPayable = document.getElementById('prevTotalPayable');
    const prevMonthlyDue   = document.getElementById('prevMonthlyDue');

    function formatPesoJS(num) {
        return '₱' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updatePreview() {
        const principal = Math.max(0, parseFloat(principalInput.value) || 0);
        const rate      = Math.max(0, parseFloat(rateInput.value) || 0);
        const term      = Math.max(1, parseInt(termInput.value, 10) || 1);

        const totalInterest = Math.round(principal * (rate / 100) * term * 100) / 100;
        const totalPayable  = Math.round((principal + totalInterest) * 100) / 100;
        const monthlyDue    = Math.round((totalPayable / term) * 100) / 100;

        prevPrincipal.textContent    = formatPesoJS(principal);
        prevRateTerm.textContent     = rate.toFixed(2) + '% × ' + term + ' mos';
        prevInterest.textContent     = formatPesoJS(totalInterest);
        prevTotalPayable.textContent = formatPesoJS(totalPayable);
        prevMonthlyDue.textContent   = formatPesoJS(monthlyDue) + ' / month';
    }

    [principalInput, rateInput, termInput].forEach(function (el) {
        if (el) {
            el.addEventListener('input', updatePreview);
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
