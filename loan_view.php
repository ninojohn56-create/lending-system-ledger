<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$pdo = getDbConnection();
$loanId = (int)($_GET['id'] ?? 0);

if ($loanId <= 0) {
    setFlash('danger', 'Invalid loan ID requested.');
    header("Location: loans.php");
    exit;
}

$loan = getLoanDetails($pdo, $loanId);
if (!$loan) {
    setFlash('danger', 'Loan account not found.');
    header("Location: loans.php");
    exit;
}

// Calculate penalty if overdue
$penaltyReport = calculateOverduePenalty($pdo, $loanId);

// Generate monthly amortization schedule
$schedule = generateAmortizationSchedule($loan);

require_once __DIR__ . '/header.php';
?>

<!-- Breadcrumb and Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="loans.php" class="text-decoration-none">Loans</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($loan['loan_code']) ?></li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Loan Account: <span class="text-primary"><?= htmlspecialchars($loan['loan_code']) ?></span></h2>
    </div>

    <!-- State Transition Action Buttons -->
    <div class="d-flex flex-wrap gap-2">
        <?php if ($loan['status'] === 'pending'): ?>
            <button type="button" class="btn btn-success d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#disburseModal">
                <i class="bi bi-send-check"></i> Approve & Disburse
            </button>
            <button type="button" class="btn btn-outline-danger d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-circle"></i> Reject
            </button>
        <?php elseif ($loan['status'] === 'approved'): ?>
            <button type="button" class="btn btn-primary d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#disburseModal">
                <i class="bi bi-cash-coin"></i> Disburse Funds
            </button>
        <?php elseif ($loan['status'] === 'disbursed' && $loan['remaining_balance'] > 0): ?>
            <button type="button" class="btn btn-primary d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#paymentModal">
                <i class="bi bi-credit-card-2-front"></i> Record Repayment
            </button>
        <?php elseif ($loan['status'] === 'completed'): ?>
            <span class="badge bg-success-subtle text-success border border-success p-2 fs-6">
                <i class="bi bi-check-circle-fill me-1"></i> Loan Fully Settled
            </span>
        <?php endif; ?>
        
        <a href="loans.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Loans
        </a>
    </div>
</div>

<!-- Delinquency Warning Alert -->
<?php if ($penaltyReport['is_overdue']): ?>
    <div class="alert alert-danger shadow-sm d-flex align-items-center mb-4">
        <i class="bi bi-exclamation-octagon-fill fs-2 me-3"></i>
        <div class="flex-grow-1">
            <h5 class="alert-heading fw-bold mb-1">Delinquent Account: Overdue by <?= $penaltyReport['days_overdue'] ?> Days!</h5>
            <p class="mb-0">
                This loan passed its maturity date on <strong><?= formatDate($loan['due_date']) ?></strong>. 
                Computed late penalty fee: <strong><?= formatCurrency($penaltyReport['penalty_amount']) ?></strong> (at <?= $penaltyReport['penalty_rate_pct'] ?>% rate).
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Left Column: Borrower Profile -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-person-badge text-primary me-2"></i> Borrower Profile</h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="avatar bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 70px; height: 70px;">
                        <i class="bi bi-person-fill fs-1"></i>
                    </div>
                    <a href="borrower_view.php?id=<?= $loan['borrower_id'] ?>" class="text-decoration-none">
                        <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?></h5>
                    </a>
                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($loan['borrower_code']) ?></span>
                </div>

                <ul class="list-group list-group-flush mb-3">
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span class="text-muted"><i class="bi bi-envelope me-1"></i> Email</span>
                        <span class="fw-semibold text-break"><?= htmlspecialchars($loan['email']) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span class="text-muted"><i class="bi bi-telephone me-1"></i> Phone</span>
                        <span class="fw-semibold"><?= htmlspecialchars($loan['phone']) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 py-2">
                        <span class="text-muted"><i class="bi bi-cash me-1"></i> Monthly Income</span>
                        <span class="fw-bold text-success"><?= formatCurrency($loan['monthly_income']) ?></span>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <div class="text-muted mb-1"><i class="bi bi-geo-alt me-1"></i> Residential Address</div>
                        <div class="small fw-semibold"><?= htmlspecialchars($loan['address']) ?></div>
                    </li>
                </ul>

                <a href="borrower_view.php?id=<?= $loan['borrower_id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                    <i class="bi bi-person-lines-fill me-1"></i> View 360 Borrower Dossier
                </a>
            </div>
        </div>
    </div>

    <!-- Right Column: Loan Terms & Repayment Progress -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-text text-primary me-2"></i> Financial Specifications</h5>
                <span class="badge badge-status-<?= $loan['status'] ?> fs-6">
                    <?= strtoupper($loan['status']) ?>
                </span>
            </div>
            <div class="card-body">
                <!-- Progress bar -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-bold text-secondary">Repayment Settlement Progress</span>
                        <span class="fw-bold text-primary"><?= $loan['progress_pct'] ?>% Paid</span>
                    </div>
                    <div class="progress" style="height: 12px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $loan['progress_pct'] ?>%"></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Loan Plan</span>
                            <span class="fw-bold text-dark"><?= htmlspecialchars($loan['plan_name']) ?></span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Interest Rate & Term</span>
                            <span class="fw-bold text-dark"><?= $loan['interest_rate'] ?>% for <?= $loan['tenure_months'] ?> Months</span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Monthly Installment</span>
                            <span class="fw-bold text-dark"><?= formatCurrency($loan['monthly_installment']) ?></span>
                        </div>
                    </div>

                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Principal Borrowed</span>
                            <span class="fw-bold text-dark"><?= formatCurrency($loan['principal_amount']) ?></span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Calculated Interest</span>
                            <span class="fw-bold text-dark"><?= formatCurrency($loan['interest_amount']) ?></span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Total Loan Obligation</span>
                            <span class="fw-bold text-primary"><?= formatCurrency($loan['total_payable']) ?></span>
                        </div>
                    </div>

                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Total Paid to Date</span>
                            <span class="fw-bold text-success"><?= formatCurrency($loan['total_paid']) ?></span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Remaining Balance</span>
                            <span class="fw-bold text-<?= ($loan['remaining_balance'] > 0) ? 'danger' : 'success' ?>">
                                <?= formatCurrency($loan['remaining_balance']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="small text-muted d-block">Final Maturity Due Date</span>
                            <span class="fw-bold text-dark"><?= formatDate($loan['due_date']) ?></span>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <span class="small text-muted">Stated Loan Purpose:</span>
                    <p class="mb-0 fw-medium text-dark"><?= htmlspecialchars($loan['purpose']) ?></p>
                </div>

                <div class="row g-3 mt-1 pt-3 border-top">
                    <div class="col-md-6">
                        <span class="small text-muted d-block"><i class="bi bi-shield-check me-1"></i>Collateral / Security:</span>
                        <span class="fw-semibold text-dark"><?= htmlspecialchars($loan['collateral_info'] ?: 'None / Unsecured') ?></span>
                    </div>
                    <div class="col-md-6">
                        <span class="small text-muted d-block"><i class="bi bi-person-check me-1"></i>Guarantor / Co-Maker:</span>
                        <span class="fw-semibold text-dark">
                            <?= htmlspecialchars($loan['guarantor_name'] ?: 'None') ?>
                            <?php if (!empty($loan['guarantor_phone']) && $loan['guarantor_phone'] !== 'None'): ?>
                                <small class="text-muted">(<?= htmlspecialchars($loan['guarantor_phone']) ?>)</small>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Amortization Schedule Table Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="bi bi-calendar3-range text-primary me-2"></i> Monthly Amortization Schedule
        </h5>
        <span class="badge bg-light text-dark border"><?= count($schedule) ?> Monthly Tenures</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Installment #</th>
                    <th>Due Date</th>
                    <th>Installment Due</th>
                    <th>Principal Portion</th>
                    <th>Interest Portion</th>
                    <th>Remaining Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedule as $row): ?>
                    <tr>
                        <td class="fw-bold">Month <?= $row['installment_no'] ?></td>
                        <td><?= formatDate($row['due_date']) ?></td>
                        <td class="fw-bold text-dark"><?= formatCurrency($row['amount_due']) ?></td>
                        <td class="text-muted"><?= formatCurrency($row['principal_part']) ?></td>
                        <td class="text-muted"><?= formatCurrency($row['interest_part']) ?></td>
                        <td class="fw-semibold"><?= formatCurrency($row['ending_balance']) ?></td>
                        <td>
                            <span class="badge <?= $row['badge_class'] ?>"><?= $row['status'] ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Repayments History Ledger Table -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="bi bi-clock-history text-primary me-2"></i> Repayment Collections Ledger
        </h5>
        <?php if ($loan['status'] === 'disbursed' && $loan['remaining_balance'] > 0): ?>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal">
                <i class="bi bi-plus-circle me-1"></i> Add Payment
            </button>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Ref #</th>
                    <th>Payment Date</th>
                    <th>Amount Paid</th>
                    <th>Penalty Included</th>
                    <th>Method</th>
                    <th>Received By</th>
                    <th>Notes</th>
                    <th class="text-end">Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loan['repayments'])): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-receipt text-secondary opacity-50 fs-2 d-block mb-1"></i>
                            No payments recorded yet for this loan account.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($loan['repayments'] as $rep): ?>
                        <tr>
                            <td class="fw-bold text-primary font-monospace"><?= htmlspecialchars($rep['transaction_reference']) ?></td>
                            <td><?= formatDate($rep['payment_date']) ?></td>
                            <td class="fw-bold text-success"><?= formatCurrency($rep['amount_paid']) ?></td>
                            <td><?= formatCurrency($rep['penalty_paid']) ?></td>
                            <td>
                                <span class="badge bg-light text-dark border text-uppercase"><?= htmlspecialchars($rep['payment_method']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($rep['received_by']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($rep['receipt_notes'] ?? 'None') ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary print-voucher-btn"
                                    data-ref="<?= htmlspecialchars($rep['transaction_reference']) ?>"
                                    data-date="<?= formatDate($rep['payment_date']) ?>"
                                    data-amount="<?= formatCurrency($rep['amount_paid']) ?>"
                                    data-penalty="<?= formatCurrency($rep['penalty_paid']) ?>"
                                    data-method="<?= htmlspecialchars(strtoupper(str_replace('_', ' ', $rep['payment_method']))) ?>"
                                    data-notes="<?= htmlspecialchars($rep['receipt_notes'] ?? 'Installment Payment') ?>"
                                    data-receiver="<?= htmlspecialchars($rep['received_by']) ?>">
                                    <i class="bi bi-printer me-1"></i> Voucher
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Record Repayment -->
<!-- ===================================================================== -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-credit-card-2-front me-1"></i> Post Repayment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="alert alert-light border mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Outstanding Balance:</span>
                            <strong class="text-danger"><?= formatCurrency($loan['remaining_balance']) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <span>Monthly Installment:</span>
                            <strong><?= formatCurrency($loan['monthly_installment']) ?></strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount to Pay (<?= APP_CURRENCY ?>) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="1" max="<?= $loan['remaining_balance'] ?>" 
                               name="amount" class="form-control form-control-lg" 
                               value="<?= min($loan['remaining_balance'], $loan['monthly_installment']) ?>" required>
                        <div class="invalid-feedback">Amount must be between 1 and <?= $loan['remaining_balance'] ?>.</div>
                    </div>

                    <?php if ($penaltyReport['is_overdue'] && $penaltyReport['penalty_amount'] > 0): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Penalty Included (<?= APP_CURRENCY ?>)</label>
                            <input type="number" step="0.01" name="penalty_paid" class="form-control" value="<?= $penaltyReport['penalty_amount'] ?>">
                            <small class="text-muted">Calculated late fee based on overdue term.</small>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Channel <span class="text-danger">*</span></label>
                        <select name="payment_method" class="form-select" required>
                            <option value="cash">Cash in Hand</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="gcash">GCash / E-Wallet</option>
                            <option value="check">Check</option>
                            <option value="online">Online Payment</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Receipt / Reference Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Deposit slip #89123">
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Post Transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Approve & Disburse -->
<!-- ===================================================================== -->
<div class="modal fade" id="disburseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST">
                <input type="hidden" name="action" value="update_loan_status">
                <input type="hidden" name="status_action" value="disburse">
                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-send-check me-1"></i> Disburse Loan Capital</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <i class="bi bi-cash-stack text-success fs-1 mb-3"></i>
                    <h5>Disburse <strong><?= formatCurrency($loan['principal_amount']) ?></strong> to borrower?</h5>
                    <p class="text-muted small">
                        This action will mark the loan account as <strong>DISBURSED</strong>, establish today as release date, and activate the repayment schedule.
                    </p>
                    <div class="text-start mt-3">
                        <label class="form-label fw-semibold">Disbursement Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2" placeholder="e.g. Released via cheque #102948"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4">Confirm Disbursement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Reject Loan -->
<!-- ===================================================================== -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST">
                <input type="hidden" name="action" value="update_loan_status">
                <input type="hidden" name="status_action" value="reject">
                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-x-circle me-1"></i> Reject Loan Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p>Provide the reason for rejecting loan application <strong><?= htmlspecialchars($loan['loan_code']) ?></strong>:</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="e.g. Insufficient proof of income, credit score check failed" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Printable Official Payment Receipt Voucher -->
<!-- ===================================================================== -->
<div class="modal fade" id="receiptVoucherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-receipt-cutoff me-2"></i>Official Payment Receipt Voucher</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="printableReceiptArea">
                <div class="receipt-box border border-2 rounded-3 p-4 bg-white text-dark shadow-sm">
                    <!-- Receipt Header -->
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary p-2 rounded-3 fs-5">
                                <i class="bi bi-wallet2"></i>
                            </span>
                            <div>
                                <h4 class="fw-bold mb-0 text-primary"><?= APP_NAME ?></h4>
                                <small class="text-muted">Official Lending & Microfinance Operations</small>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-success-subtle text-success border border-success-subtle fs-6 text-uppercase">Payment Acknowledged</span>
                            <div class="small text-muted mt-1">Receipt Date: <strong id="voucherDate">-</strong></div>
                        </div>
                    </div>

                    <!-- Receipt Details Grid -->
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <small class="text-muted text-uppercase fw-semibold d-block">Transaction Reference</small>
                            <span class="fw-bold font-monospace text-primary fs-5" id="voucherRef">-</span>
                        </div>
                        <div class="col-6 text-end">
                            <small class="text-muted text-uppercase fw-semibold d-block">Loan Account Code</small>
                            <span class="fw-bold font-monospace text-dark fs-5"><?= htmlspecialchars($loan['loan_code']) ?></span>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Borrower Name:</small>
                                <strong class="fs-6"><?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Borrower ID Code:</small>
                                <strong class="fs-6"><?= htmlspecialchars($loan['borrower_code']) ?></strong>
                            </div>
                            <div class="col-sm-6 mt-2">
                                <small class="text-muted d-block">Loan Plan:</small>
                                <span><?= htmlspecialchars($loan['plan_name']) ?> (<?= $loan['interest_rate'] ?>%)</span>
                            </div>
                            <div class="col-sm-6 mt-2">
                                <small class="text-muted d-block">Payment Channel:</small>
                                <strong class="text-uppercase" id="voucherMethod">-</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Amount Summary Table -->
                    <table class="table table-bordered mb-3">
                        <tbody>
                            <tr>
                                <td class="fw-semibold">Installment Principal & Interest Remitted:</td>
                                <td class="text-end fw-bold text-success fs-5" id="voucherAmount">-</td>
                            </tr>
                            <tr>
                                <td class="fw-semibold">Overdue Late Penalties Paid:</td>
                                <td class="text-end fw-semibold text-danger" id="voucherPenalty">-</td>
                            </tr>
                            <tr>
                                <td class="fw-semibold">Payment / Transaction Notes:</td>
                                <td class="text-end small text-muted" id="voucherNotes">-</td>
                            </tr>
                            <tr class="table-light">
                                <td class="fw-bold">Remaining Loan Balance After Payment:</td>
                                <td class="text-end fw-bold text-primary fs-5"><?= formatCurrency($loan['remaining_balance']) ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Signatures & Authority -->
                    <div class="row pt-4 mt-4 border-top text-center">
                        <div class="col-6">
                            <div class="border-bottom pb-1 mx-auto" style="max-width: 200px;">
                                <strong id="voucherReceiver">Admin</strong>
                            </div>
                            <small class="text-muted">Authorized Collection Officer</small>
                        </div>
                        <div class="col-6">
                            <div class="border-bottom pb-1 mx-auto" style="max-width: 200px;">
                                <strong><?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?></strong>
                            </div>
                            <small class="text-muted">Client / Payer Signature</small>
                        </div>
                    </div>
                    
                    <div class="text-center text-muted small mt-4 pt-2 border-top">
                        <i class="bi bi-shield-check me-1 text-success"></i> Computer Generated Official Receipt Voucher &middot; <?= APP_NAME ?> Financial Ledger
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary px-4" onclick="printReceiptVoucher()">
                    <i class="bi bi-printer-fill me-1"></i> Print Receipt Voucher
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    #printableReceiptArea, #printableReceiptArea * {
        visibility: visible !important;
    }
    #printableReceiptArea {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Voucher button event delegation
    document.querySelectorAll('.print-voucher-btn').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('voucherRef').textContent = this.dataset.ref;
            document.getElementById('voucherDate').textContent = this.dataset.date;
            document.getElementById('voucherAmount').textContent = this.dataset.amount;
            document.getElementById('voucherPenalty').textContent = this.dataset.penalty;
            document.getElementById('voucherMethod').textContent = this.dataset.method;
            document.getElementById('voucherNotes').textContent = this.dataset.notes;
            document.getElementById('voucherReceiver').textContent = this.dataset.receiver || 'Admin';

            const modal = new bootstrap.Modal(document.getElementById('receiptVoucherModal'));
            modal.show();
        });
    });
});

function printReceiptVoucher() {
    window.print();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
