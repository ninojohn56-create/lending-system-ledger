<?php
/**
 * Record Loan Payment (Controlled PDO Transaction & Balance Updater)
 * File: payments/add.php
 *
 * Implements:
 * - System Function #11: Record loan payments
 * - System Function #12: Automatically calculate the remaining loan balance
 * - System Function #13: Track loan statuses (marks loan 'paid' when balance reaches 0.00)
 *
 * How Balance Consistency & Duplicate Prevention Work:
 * 1. Uses a MySQL InnoDB transaction ($pdo->beginTransaction() ... $pdo->commit()).
 * 2. Locks the target loan row using `SELECT ... FOR UPDATE` so two concurrent requests cannot
 *    race-condition the balance.
 * 3. Recomputes the exact remaining balance from the immutable `payments` table
 *    (total_payable - SUM(payments.amount)) before accepting the new payment.
 * 4. Enforces `amount > 0` and `amount <= current_balance` (prevents overpayments).
 * 5. Enforces a unique `reference_no` and a one-time session submission token to prevent duplicate
 *    payment submissions on browser refresh or double-click.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
requireLogin();
syncOverdueLoans($pdo);

// Fetch all payable loans (active, partially_paid, or overdue with balance > 0)
$payableLoansStmt = $pdo->query(
    "SELECT
        l.id,
        l.loan_no,
        l.principal,
        l.interest_rate,
        l.term_months,
        l.total_payable,
        l.monthly_due,
        l.balance,
        l.due_date,
        l.status,
        b.borrower_no,
        b.full_name AS borrower_name,
        b.phone AS borrower_phone
     FROM loans l
     INNER JOIN borrowers b ON b.id = l.borrower_id
     WHERE l.status IN ('active', 'partially_paid', 'overdue')
       AND l.balance > 0
     ORDER BY l.id DESC"
);
$payableLoans = $payableLoansStmt->fetchAll();

// Map loans by ID for quick lookup
$loansById = [];
foreach ($payableLoans as $l) {
    $loansById[(int)$l['id']] = $l;
}

// Generate or retrieve one-time idempotency token to prevent double-submit
if (empty($_SESSION['payment_submission_token'])) {
    $_SESSION['payment_submission_token'] = bin2hex(random_bytes(16));
}

$errors        = [];
$selectedLoanId = isset($_GET['loan_id']) ? (int)$_GET['loan_id'] : (int)($_POST['loan_id'] ?? 0);
if ($selectedLoanId === 0 && !empty($payableLoans)) {
    $selectedLoanId = (int)$payableLoans[0]['id'];
}

$referenceNo   = generateNextCode($pdo, 'payments', 'reference_no', 'OR', 3);
$paymentDate   = date('Y-m-d');
$paymentMethod = 'Cash';
$amount        = '';
$remarks       = '';

// Pre-fill default suggested amount (smaller of monthly_due or remaining balance)
if ($selectedLoanId > 0 && isset($loansById[$selectedLoanId]) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sel    = $loansById[$selectedLoanId];
    $sugAmt = min((float)$sel['monthly_due'], (float)$sel['balance']);
    $amount = number_format($sugAmt, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid CSRF security token. Please refresh the page and try again.';
    } else {
        $submittedIdempotency = (string)($_POST['submission_token'] ?? '');
        if (
            empty($_SESSION['payment_submission_token']) ||
            !hash_equals($_SESSION['payment_submission_token'], $submittedIdempotency)
        ) {
            $errors[] = 'Duplicate payment submission detected. Please verify the payment ledger before submitting again.';
        }

        $selectedLoanId = (int)($_POST['loan_id'] ?? 0);
        $referenceNo    = strtoupper(trim((string)($_POST['reference_no'] ?? '')));
        $paymentDate    = trim((string)($_POST['payment_date'] ?? ''));
        $paymentMethod  = trim((string)($_POST['payment_method'] ?? 'Cash'));
        $amount         = trim((string)($_POST['amount'] ?? ''));
        $remarks        = trim((string)($_POST['remarks'] ?? ''));

        $allowedMethods = ['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Check'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            $errors[] = 'Please select a valid payment method.';
        }

        if ($selectedLoanId <= 0) {
            $errors[] = 'Please select a valid active loan to apply this payment to.';
        }

        if ($referenceNo === '' || !preg_match('/^[A-Z0-9-]{4,50}$/', $referenceNo)) {
            $errors[] = 'Official Receipt / Reference No. is required (4–50 uppercase letters, digits, or hyphens).';
        }

        if ($paymentDate === '' || strtotime($paymentDate) === false) {
            $errors[] = 'Please provide a valid payment date.';
        }

        if (!is_numeric($amount) || (float)$amount <= 0) {
            $errors[] = 'Payment amount must be a positive number greater than ₱0.00.';
        }

        // Check duplicate reference_no before starting transaction
        if (empty($errors)) {
            $dupRef = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE reference_no = :ref');
            $dupRef->execute([':ref' => $referenceNo]);
            if ((int)$dupRef->fetchColumn() > 0) {
                $errors[] = 'Receipt / Reference No. "' . $referenceNo . '" has already been used. Duplicate payment references are not allowed.';
            }
        }

        if (empty($errors)) {
            $paymentAmount = round((float)$amount, 2);

            try {
                // Begin controlled financial transaction
                $pdo->beginTransaction();

                // 1. Lock the loan record with FOR UPDATE
                $loanStmt = $pdo->prepare('SELECT * FROM loans WHERE id = :id FOR UPDATE');
                $loanStmt->execute([':id' => $selectedLoanId]);
                $loan = $loanStmt->fetch();

                if (!$loan) {
                    $pdo->rollBack();
                    $errors[] = 'The selected loan record does not exist.';
                } elseif (!in_array($loan['status'], ['active', 'partially_paid', 'overdue'], true)) {
                    $pdo->rollBack();
                    $errors[] = 'Payments can only be recorded against Active, Partially Paid, or Overdue loans.';
                } else {
                    // 2. Audit remaining balance directly from the payments ledger
                    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE loan_id = :loan_id');
                    $sumStmt->execute([':loan_id' => $selectedLoanId]);
                    $alreadyPaid = round((float)$sumStmt->fetchColumn(), 2);

                    $trueCurrentBalance = max(0.00, round((float)$loan['total_payable'] - $alreadyPaid, 2));

                    // 3. Prevent overpayment
                    if ($paymentAmount > $trueCurrentBalance + 0.001) {
                        $pdo->rollBack();
                        $errors[] = 'Overpayment rejected: The payment amount (' . formatPeso($paymentAmount)
                            . ') exceeds the loan\'s remaining balance of ' . formatPeso($trueCurrentBalance) . '.';
                    } else {
                        // 4. Insert immutable payment ledger record
                        $insertPmt = $pdo->prepare(
                            'INSERT INTO payments (
                                loan_id, amount, payment_date, payment_method, reference_no, recorded_by, remarks
                             ) VALUES (
                                :loan_id, :amount, :payment_date, :payment_method, :reference_no, :recorded_by, :remarks
                             )'
                        );
                        $insertPmt->execute([
                            ':loan_id'        => $selectedLoanId,
                            ':amount'         => $paymentAmount,
                            ':payment_date'   => $paymentDate,
                            ':payment_method' => $paymentMethod,
                            ':reference_no'   => $referenceNo,
                            ':recorded_by'    => (int)$_SESSION['user_id'],
                            ':remarks'        => $remarks !== '' ? $remarks : null,
                        ]);

                        $newPaymentId = (int)$pdo->lastInsertId();

                        // 5. Calculate exact new balance and determine updated loan status
                        $newBalance = max(0.00, round($trueCurrentBalance - $paymentAmount, 2));

                        if ($newBalance <= 0.00) {
                            $newBalance = 0.00;
                            $newStatus  = 'paid';
                        } elseif ($loan['due_date'] < date('Y-m-d')) {
                            $newStatus = 'overdue';
                        } else {
                            $newStatus = 'partially_paid';
                        }

                        // 6. Update loan balance and status inside the same transaction
                        $updLoan = $pdo->prepare(
                            'UPDATE loans
                             SET balance = :balance,
                                 status  = :status
                             WHERE id = :id'
                        );
                        $updLoan->execute([
                            ':balance' => $newBalance,
                            ':status'  => $newStatus,
                            ':id'      => $selectedLoanId,
                        ]);

                        $pdo->commit();

                        // Rotate one-time submission token after successful commit
                        $_SESSION['payment_submission_token'] = bin2hex(random_bytes(16));

                        $statusNote = $newStatus === 'paid'
                            ? 'Loan ' . $loan['loan_no'] . ' is now PAID IN FULL!'
                            : 'Remaining balance is now ' . formatPeso($newBalance) . '.';

                        setFlash(
                            'success',
                            'Payment of ' . formatPeso($paymentAmount) . ' (' . $referenceNo . ') recorded successfully! ' . $statusNote
                        );
                        header('Location: ' . baseUrl('payments/index.php?receipt_id=' . $newPaymentId));
                        exit;
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Payment recording error: ' . $e->getMessage());
                $errors[] = 'Could not record the payment due to a database error. Please verify the reference number is unique.';
            }
        }
    }
}

$currentLoan = $loansById[$selectedLoanId] ?? null;

$pageTitle  = 'Record Loan Payment';
$activeMenu = 'payments';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('dashboard.php')) ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= e(baseUrl('payments/index.php')) ?>">Payments</a></li>
        <li class="breadcrumb-item active" aria-current="page">Record Payment</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">Record Loan Repayment</h3>
        <p class="text-secondary mb-0">Post a borrower payment, automatically update the loan balance, and generate an official receipt.</p>
    </div>
    <a href="<?= e(baseUrl('payments/index.php')) ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Payments
    </a>
</div>

<?php if (empty($payableLoans)): ?>
    <div class="alert alert-info d-flex align-items-center justify-content-between">
        <div>
            <i class="bi bi-check-circle-fill me-2"></i>
            <strong>All active loans are currently settled!</strong> There are no open loans with an unpaid balance right now.
        </div>
        <a href="<?= e(baseUrl('loans/add.php')) ?>" class="btn btn-sm btn-primary">Create a New Loan</a>
    </div>
<?php else: ?>
    <div class="row g-4">
        <!-- Left Column: Payment Entry Form -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-cash-coin text-success me-2"></i>Payment Transaction Form
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-circle-fill me-1"></i>Payment Validation Error:</div>
                            <ul class="mb-0 ps-3 small">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= e($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= e(baseUrl('payments/add.php')) ?>" id="paymentForm" novalidate>
                        <?= csrfField() ?>
                        <input type="hidden" name="submission_token" value="<?= e($_SESSION['payment_submission_token']) ?>">

                        <div class="mb-3">
                            <label for="loan_id" class="form-label">Select Active Loan <span class="text-danger">*</span></label>
                            <select class="form-select" id="loan_id" name="loan_id" required>
                                <?php foreach ($payableLoans as $l): ?>
                                    <option
                                        value="<?= (int)$l['id'] ?>"
                                        data-loan-no="<?= e($l['loan_no']) ?>"
                                        data-borrower="<?= e($l['borrower_name']) ?> (<?= e($l['borrower_no']) ?>)"
                                        data-payable="<?= e((string)$l['total_payable']) ?>"
                                        data-monthly="<?= e((string)$l['monthly_due']) ?>"
                                        data-balance="<?= e((string)$l['balance']) ?>"
                                        data-due-date="<?= e(date('M d, Y', strtotime($l['due_date']))) ?>"
                                        <?= $selectedLoanId === (int)$l['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($l['loan_no']) ?> &mdash; <?= e($l['borrower_name']) ?> (Balance: <?= e(formatPeso($l['balance'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="reference_no" class="form-label">Official Receipt / Reference No. <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="reference_no"
                                    name="reference_no"
                                    value="<?= e($referenceNo) ?>"
                                    required
                                >
                                <div class="form-text">Unique receipt identifier (enforced by MySQL).</div>
                            </div>

                            <div class="col-md-6">
                                <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                                <input
                                    type="date"
                                    class="form-control"
                                    id="payment_date"
                                    name="payment_date"
                                    value="<?= e($paymentDate) ?>"
                                    required
                                >
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="amount" class="form-label">Payment Amount (<?= e(APP_CURRENCY) ?>) <span class="text-danger">*</span></label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    max="<?= $currentLoan ? e((string)$currentLoan['balance']) : '' ?>"
                                    class="form-control fw-bold"
                                    id="amount"
                                    name="amount"
                                    value="<?= e($amount) ?>"
                                    required
                                >
                                <div class="d-flex gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnPayMonthly">
                                        Fill Monthly Due
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" id="btnPayFull">
                                        Fill Full Balance
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                                <select class="form-select" id="payment_method" name="payment_method">
                                    <?php foreach (['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Check'] as $m): ?>
                                        <option value="<?= e($m) ?>" <?= $paymentMethod === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="remarks" class="form-label">Payment Notes / Remarks</label>
                            <input
                                type="text"
                                class="form-control"
                                id="remarks"
                                name="remarks"
                                value="<?= e($remarks) ?>"
                                placeholder="Optional remarks (e.g., 2nd monthly installment, Full settlement)"
                            >
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success px-4">
                                <i class="bi bi-check2-circle me-1"></i>Confirm &amp; Record Payment
                            </button>
                            <a href="<?= e(baseUrl('payments/index.php')) ?>" class="btn btn-light border">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Column: Selected Loan & Live Balance Impact Preview -->
        <div class="col-lg-5">
            <div class="card border-success shadow-sm">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-shield-check me-2"></i>Selected Loan &amp; Balance Preview
                </div>
                <div class="card-body p-4">
                    <div class="bg-light rounded-3 p-3 border mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Loan Number:</span>
                            <span class="fw-bold" id="infoLoanNo"><?= $currentLoan ? e($currentLoan['loan_no']) : '—' ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Borrower:</span>
                            <span class="fw-medium text-end" id="infoBorrower"><?= $currentLoan ? e($currentLoan['borrower_name'] . ' (' . $currentLoan['borrower_no'] . ')') : '—' ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Total Scheduled Payable:</span>
                            <span id="infoPayable"><?= $currentLoan ? e(formatPeso($currentLoan['total_payable'])) : '₱0.00' ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Monthly Installment Guide:</span>
                            <span class="text-success fw-medium" id="infoMonthly"><?= $currentLoan ? e(formatPeso($currentLoan['monthly_due'])) : '₱0.00' ?></span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold">Current Unpaid Balance:</span>
                            <span class="fs-5 fw-bold text-primary" id="infoCurrentBalance"><?= $currentLoan ? e(formatPeso($currentLoan['balance'])) : '₱0.00' ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold text-dark">Projected Balance After Payment:</span>
                            <span class="fs-6 fw-bold text-success" id="infoProjectedBalance">₱0.00</span>
                        </div>
                    </div>

                    <div class="small text-secondary">
                        <div class="fw-bold text-dark mb-1"><i class="bi bi-lock me-1"></i>Transaction Integrity Rules:</div>
                        <ul class="ps-3 mb-0">
                            <li class="mb-1">Partial payments are supported and automatically mark the loan as <strong>Partially Paid</strong>.</li>
                            <li class="mb-1">Overpayments above the current balance are automatically rejected.</li>
                            <li>When the remaining balance reaches <strong>₱0.00</strong>, the loan status automatically updates to <strong>Paid</strong>.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const loanSelect   = document.getElementById('loan_id');
        const amountInput  = document.getElementById('amount');
        const btnMonthly   = document.getElementById('btnPayMonthly');
        const btnFull      = document.getElementById('btnPayFull');

        const infoLoanNo           = document.getElementById('infoLoanNo');
        const infoBorrower         = document.getElementById('infoBorrower');
        const infoPayable          = document.getElementById('infoPayable');
        const infoMonthly          = document.getElementById('infoMonthly');
        const infoCurrentBalance   = document.getElementById('infoCurrentBalance');
        const infoProjectedBalance = document.getElementById('infoProjectedBalance');

        function formatPesoJS(num) {
            return '₱' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function getSelectedLoanData() {
            const opt = loanSelect.options[loanSelect.selectedIndex];
            if (!opt) return null;
            return {
                loanNo:   opt.getAttribute('data-loan-no') || '',
                borrower: opt.getAttribute('data-borrower') || '',
                payable:  parseFloat(opt.getAttribute('data-payable') || '0'),
                monthly:  parseFloat(opt.getAttribute('data-monthly') || '0'),
                balance:  parseFloat(opt.getAttribute('data-balance') || '0')
            };
        }

        function refreshSummary(resetAmount) {
            const data = getSelectedLoanData();
            if (!data) return;

            infoLoanNo.textContent         = data.loanNo;
            infoBorrower.textContent       = data.borrower;
            infoPayable.textContent        = formatPesoJS(data.payable);
            infoMonthly.textContent        = formatPesoJS(data.monthly);
            infoCurrentBalance.textContent = formatPesoJS(data.balance);
            amountInput.max                = data.balance.toFixed(2);

            if (resetAmount) {
                const suggested = Math.min(data.monthly, data.balance);
                amountInput.value = suggested.toFixed(2);
            }

            const entered = Math.max(0, parseFloat(amountInput.value) || 0);
            const projected = Math.max(0, Math.round((data.balance - entered) * 100) / 100);
            infoProjectedBalance.textContent = formatPesoJS(projected) + (projected === 0 ? ' (PAID IN FULL)' : '');
        }

        loanSelect.addEventListener('change', function () {
            refreshSummary(true);
        });

        amountInput.addEventListener('input', function () {
            refreshSummary(false);
        });

        btnMonthly.addEventListener('click', function () {
            const data = getSelectedLoanData();
            if (!data) return;
            amountInput.value = Math.min(data.monthly, data.balance).toFixed(2);
            refreshSummary(false);
        });

        btnFull.addEventListener('click', function () {
            const data = getSelectedLoanData();
            if (!data) return;
            amountInput.value = data.balance.toFixed(2);
            refreshSummary(false);
        });

        refreshSummary(false);
    })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
