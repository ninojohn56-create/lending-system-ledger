<?php
/**
 * 360-Degree Borrower Profile & Credit Dossier
 * Shows complete credit history, active exposure, payment records, and financial standing.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$borrowerId = (int)($_GET['id'] ?? 0);
$pdo = getDbConnection();
$profile = getBorrowerProfile($pdo, $borrowerId);

if (!$profile || !$profile['borrower']) {
    setFlash('danger', 'Borrower account not found or has been removed.');
    header("Location: borrowers.php");
    exit;
}

$b = $profile['borrower'];
$loans = $profile['loans'];
$repayments = $profile['repayments'];

// Calculate Debt-to-Income (DTI) ratio
$activeMonthlyCommitment = 0.0;
foreach ($loans as $l) {
    if ($l['status'] === 'disbursed') {
        $activeMonthlyCommitment += (float)$l['monthly_installment'];
    }
}
$monthlyIncome = (float)$b['monthly_income'];
$dtiRatio = ($monthlyIncome > 0) ? round(($activeMonthlyCommitment / $monthlyIncome) * 100, 1) : 0;

// Fetch active loan plans for the quick new loan modal
$activePlans = getLoanPlans($pdo, true);

$pageTitle = "Borrower Dossier: " . $b['first_name'] . ' ' . $b['last_name'];
require_once __DIR__ . '/header.php';
?>

<!-- Breadcrumb Navigation -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="borrowers.php">Borrowers</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($b['borrower_code']) ?></li>
    </ol>
</nav>

<!-- Page Header & Action Bar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h3 class="fw-bold mb-0">
                <?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?>
            </h3>
            <span class="badge bg-<?= $profile['rating_class'] ?>-subtle text-<?= $profile['rating_class'] ?> border border-<?= $profile['rating_class'] ?>-subtle fs-6">
                <i class="bi bi-shield-check me-1"></i><?= $profile['credit_rating'] ?>
            </span>
        </div>
        <div class="text-muted small">
            Borrower Code: <strong class="text-primary"><?= htmlspecialchars($b['borrower_code']) ?></strong> &middot;
            Member since: <?= formatDate($b['created_at']) ?> &middot;
            Status: <span class="badge bg-<?= ($b['status'] === 'active') ? 'success' : 'secondary' ?>"><?= strtoupper($b['status']) ?></span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#newLoanForBorrowerModal">
            <i class="bi bi-plus-circle"></i>
            <span>Apply New Loan</span>
        </button>
        <button class="btn btn-outline-secondary d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#editBorrowerModal">
            <i class="bi bi-pencil-square"></i>
            <span>Edit Profile</span>
        </button>
        <a href="borrowers.php" class="btn btn-light border">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<!-- Financial Summary KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-primary"><i class="bi bi-cash-stack"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Lifetime Borrowed</span>
            <h4 class="fw-bold mt-2 mb-0"><?= formatCurrency($profile['total_borrowed']) ?></h4>
            <small class="text-muted mt-1"><?= $profile['total_loans'] ?> loan application(s) total</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-success"><i class="bi bi-check-circle"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Lifetime Repaid</span>
            <h4 class="fw-bold text-success mt-2 mb-0"><?= formatCurrency($profile['total_repaid']) ?></h4>
            <small class="text-muted mt-1"><?= count($repayments) ?> payment installment(s) made</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-danger"><i class="bi bi-hourglass-split"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Outstanding Balance</span>
            <h4 class="fw-bold <?= ($profile['current_debt'] > 0) ? 'text-danger' : 'text-success' ?> mt-2 mb-0">
                <?= formatCurrency($profile['current_debt']) ?>
            </h4>
            <small class="text-muted mt-1"><?= $profile['active_loans'] ?> currently active loan(s)</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-info"><i class="bi bi-pie-chart"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Debt-to-Income (DTI)</span>
            <h4 class="fw-bold text-info mt-2 mb-0"><?= $dtiRatio ?>%</h4>
            <small class="text-muted mt-1">Monthly commitment: <?= formatCurrency($activeMonthlyCommitment) ?></small>
        </div>
    </div>
</div>

<!-- Main Row: Profile Details & Records Tabs -->
<div class="row g-4 mb-4">
    <!-- Left Column: Borrower Information Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Personal & Financial Info</h6>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush mb-0">
                    <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                        <span class="text-muted"><i class="bi bi-hash me-1"></i>Borrower Code:</span>
                        <strong class="text-dark"><?= htmlspecialchars($b['borrower_code']) ?></strong>
                    </li>
                    <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                        <span class="text-muted"><i class="bi bi-envelope me-1"></i>Email:</span>
                        <a href="mailto:<?= htmlspecialchars($b['email']) ?>" class="text-decoration-none fw-semibold">
                            <?= htmlspecialchars($b['email']) ?>
                        </a>
                    </li>
                    <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                        <span class="text-muted"><i class="bi bi-telephone me-1"></i>Phone:</span>
                        <span class="fw-semibold text-dark"><?= htmlspecialchars($b['phone']) ?></span>
                    </li>
                    <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                        <span class="text-muted"><i class="bi bi-wallet2 me-1"></i>Monthly Income:</span>
                        <span class="fw-bold text-success"><?= formatCurrency($b['monthly_income']) ?></span>
                    </li>
                    <li class="list-group-item px-0 py-2">
                        <div class="text-muted mb-1"><i class="bi bi-geo-alt me-1"></i>Residential Address:</div>
                        <div class="text-dark fw-medium ps-3 border-start border-2 border-primary">
                            <?= nl2br(htmlspecialchars($b['address'])) ?>
                        </div>
                    </li>
                    <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                        <span class="text-muted"><i class="bi bi-calendar3 me-1"></i>Account Created:</span>
                        <span class="text-muted"><?= formatDate($b['created_at']) ?></span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Credit Standing Guide -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-shield-shaded me-2 text-primary"></i>Credit Assessment Summary</h6>
            </div>
            <div class="card-body">
                <?php if ($profile['has_overdue']): ?>
                    <div class="alert alert-danger mb-0 d-flex gap-2 align-items-center">
                        <i class="bi bi-exclamation-octagon-fill fs-3"></i>
                        <div>
                            <strong>Delinquency Warning:</strong> This borrower has past-due loan balances. Follow up on collections before extending new credit.
                        </div>
                    </div>
                <?php elseif ($profile['completed_loans'] > 0 && $profile['active_loans'] === 0): ?>
                    <div class="alert alert-success mb-0 d-flex gap-2 align-items-center">
                        <i class="bi bi-check-circle-fill fs-3"></i>
                        <div>
                            <strong>Prime Borrower:</strong> Clean record with 100% historical settlement. Eligible for accelerated loan approvals.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0 d-flex gap-2 align-items-center">
                        <i class="bi bi-info-circle-fill fs-3"></i>
                        <div>
                            <strong>Standard Profile:</strong> Regular client with healthy debt-to-income margin (<?= $dtiRatio ?>%).
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Loan Portfolio & Payment History -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-2">
                <ul class="nav nav-tabs card-header-tabs" id="borrowerTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active fw-semibold" id="loans-tab" data-bs-toggle="tab" data-bs-target="#tab-loans" type="button" role="tab">
                            <i class="bi bi-cash-stack me-1"></i> Loan History (<?= count($loans) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold" id="repayments-tab" data-bs-toggle="tab" data-bs-target="#tab-repayments" type="button" role="tab">
                            <i class="bi bi-receipt me-1"></i> Payment Records (<?= count($repayments) ?>)
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-0">
                <div class="tab-content" id="borrowerTabsContent">
                    <!-- Tab 1: Loan History -->
                    <div class="tab-pane fade show active" id="tab-loans" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Loan Code</th>
                                        <th>Plan / Term</th>
                                        <th>Principal</th>
                                        <th>Total Paid</th>
                                        <th>Balance</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th class="text-end">Ledger</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($loans)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                                No loan applications found for this borrower.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($loans as $l): ?>
                                            <?php 
                                                $isOverdue = ($l['status'] === 'disbursed' && strtotime($l['due_date']) < strtotime(date('Y-m-d')) && $l['remaining_balance'] > 0);
                                            ?>
                                            <tr class="<?= $isOverdue ? 'table-warning' : '' ?>">
                                                <td>
                                                    <span class="fw-bold text-primary"><?= htmlspecialchars($l['loan_code']) ?></span>
                                                    <div class="small text-muted"><?= htmlspecialchars($l['purpose']) ?></div>
                                                </td>
                                                <td>
                                                    <div><?= htmlspecialchars($l['plan_name']) ?></div>
                                                    <small class="text-muted"><?= $l['interest_rate'] ?>% &middot; <?= $l['tenure_months'] ?> Mos</small>
                                                </td>
                                                <td class="fw-semibold"><?= formatCurrency($l['principal_amount']) ?></td>
                                                <td class="text-success"><?= formatCurrency($l['total_paid']) ?></td>
                                                <td class="fw-bold <?= ($l['remaining_balance'] > 0) ? 'text-danger' : 'text-muted' ?>">
                                                    <?= formatCurrency($l['remaining_balance']) ?>
                                                </td>
                                                <td>
                                                    <div><?= formatDate($l['due_date']) ?></div>
                                                    <?php if ($isOverdue): ?>
                                                        <span class="badge bg-danger">Overdue</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-status-<?= $l['status'] ?>">
                                                        <?= strtoupper($l['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="loan_view.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Loan Ledger">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Payment Records -->
                    <div class="tab-pane fade" id="tab-repayments" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Receipt Ref</th>
                                        <th>Loan Code</th>
                                        <th>Date</th>
                                        <th>Amount Paid</th>
                                        <th>Penalty</th>
                                        <th>Method</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($repayments)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-muted">
                                                <i class="bi bi-receipt-cutoff fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                                No payment receipts recorded yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($repayments as $r): ?>
                                            <tr>
                                                <td class="fw-bold text-primary font-monospace">
                                                    <?= htmlspecialchars($r['transaction_reference']) ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <?= htmlspecialchars($r['loan_code']) ?>
                                                    </span>
                                                </td>
                                                <td><?= formatDate($r['payment_date']) ?></td>
                                                <td class="fw-bold text-success"><?= formatCurrency($r['amount_paid']) ?></td>
                                                <td>
                                                    <?php if ($r['penalty_paid'] > 0): ?>
                                                        <span class="badge bg-danger-subtle text-danger">
                                                            <?= formatCurrency($r['penalty_paid']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border text-uppercase">
                                                        <?= htmlspecialchars(str_replace('_', ' ', $r['payment_method'])) ?>
                                                    </span>
                                                </td>
                                                <td class="small text-muted"><?= htmlspecialchars($r['receipt_notes'] ?: '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Apply New Loan for this Borrower -->
<!-- ===================================================================== -->
<div class="modal fade" id="newLoanForBorrowerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate id="loanFormBorrower">
                <input type="hidden" name="action" value="apply_loan">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="borrower_id" value="<?= $b['id'] ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Apply for Loan: <?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Loan Plan <span class="text-danger">*</span></label>
                            <select name="plan_id" class="form-select" id="planSelectBorrower" required>
                                <option value="" data-rate="0" data-months="0">-- Select Plan --</option>
                                <?php foreach ($activePlans as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-rate="<?= $p['interest_rate'] ?>" data-months="<?= $p['tenure_months'] ?>">
                                        <?= htmlspecialchars($p['plan_name']) ?> (<?= $p['interest_rate'] ?>% for <?= $p['tenure_months'] ?> mos)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a loan plan.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Principal Amount (<?= APP_CURRENCY ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="100" min="1000" name="principal_amount" id="principalInputBorrower" class="form-control" placeholder="e.g. 50000" required>
                            <div class="invalid-feedback">Minimum amount is 1,000.00.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Loan Purpose <span class="text-danger">*</span></label>
                            <input type="text" name="purpose" class="form-control" placeholder="e.g. Working capital, house repairs" required>
                            <div class="invalid-feedback">Purpose is required.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Collateral / Security Information</label>
                            <input type="text" name="collateral_info" class="form-control" placeholder="e.g. Land Title #1234, Vehicle OR/CR, None / Unsecured">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Guarantor / Co-Maker Name</label>
                            <input type="text" name="guarantor_name" class="form-control" placeholder="e.g. Juanita Dela Cruz">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Guarantor Phone Number</label>
                            <input type="text" name="guarantor_phone" class="form-control" placeholder="e.g. +63 912 345 6789">
                        </div>

                        <!-- Live Calculation Preview -->
                        <div class="col-12">
                            <div class="p-3 bg-light rounded-3 border">
                                <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-calculator"></i> Repayment Calculation Preview</h6>
                                <div class="row text-center g-2">
                                    <div class="col-4">
                                        <div class="small text-muted">Est. Interest</div>
                                        <div class="fw-bold text-dark" id="previewInterestBorrower"><?= APP_CURRENCY ?> 0.00</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">Total Payable</div>
                                        <div class="fw-bold text-primary" id="previewTotalBorrower"><?= APP_CURRENCY ?> 0.00</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">Monthly Installment</div>
                                        <div class="fw-bold text-success" id="previewMonthlyBorrower"><?= APP_CURRENCY ?> 0.00</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Submit Application</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Edit Borrower -->
<!-- ===================================================================== -->
<div class="modal fade" id="editBorrowerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="edit_borrower">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="id" value="<?= $b['id'] ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Borrower Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($b['first_name']) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($b['last_name']) ?>" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($b['email']) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($b['phone']) ?>" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Monthly Income <span class="text-danger">*</span></label>
                            <input type="number" step="100" name="monthly_income" class="form-control" value="<?= $b['monthly_income'] ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select" required>
                                <option value="active" <?= ($b['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($b['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                <option value="blacklisted" <?= ($b['status'] === 'blacklisted') ? 'selected' : '' ?>>Blacklisted</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Residential Address <span class="text-danger">*</span></label>
                        <textarea name="address" class="form-control" rows="3" required><?= htmlspecialchars($b['address']) ?></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const planSelect = document.getElementById('planSelectBorrower');
    const principalInput = document.getElementById('principalInputBorrower');
    const previewInterest = document.getElementById('previewInterestBorrower');
    const previewTotal = document.getElementById('previewTotalBorrower');
    const previewMonthly = document.getElementById('previewMonthlyBorrower');
    const currency = '<?= APP_CURRENCY ?>';

    function calculatePreview() {
        if (!planSelect || !principalInput) return;
        const selectedOpt = planSelect.options[planSelect.selectedIndex];
        const rate = parseFloat(selectedOpt.getAttribute('data-rate')) || 0;
        const months = parseInt(selectedOpt.getAttribute('data-months')) || 0;
        const principal = parseFloat(principalInput.value) || 0;

        if (principal > 0 && months > 0) {
            const interest = principal * (rate / 100);
            const total = principal + interest;
            const monthly = total / months;

            previewInterest.innerText = currency + ' ' + interest.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            previewTotal.innerText = currency + ' ' + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            previewMonthly.innerText = currency + ' ' + monthly.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        } else {
            previewInterest.innerText = currency + ' 0.00';
            previewTotal.innerText = currency + ' 0.00';
            previewMonthly.innerText = currency + ' 0.00';
        }
    }

    if (planSelect) planSelect.addEventListener('change', calculatePreview);
    if (principalInput) principalInput.addEventListener('input', calculatePreview);
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
