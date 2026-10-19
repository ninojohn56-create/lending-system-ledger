<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$pdo = getDbConnection();

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$sql = "SELECT l.*, b.first_name, b.last_name, b.borrower_code, p.plan_name, p.interest_rate, p.tenure_months 
        FROM loans l
        INNER JOIN borrowers b ON l.borrower_id = b.id
        INNER JOIN loan_plans p ON l.plan_id = p.id
        WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sql .= " AND (l.loan_code LIKE :search OR b.first_name LIKE :search OR b.last_name LIKE :search OR b.borrower_code LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($status === 'overdue') {
    $sql .= " AND l.status = 'disbursed' AND l.due_date < CURRENT_DATE AND l.remaining_balance > 0";
} elseif (!empty($status)) {
    $sql .= " AND l.status = :status";
    $params[':status'] = $status;
}

$sql .= " ORDER BY l.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();

// Borrowers and plans for the New Loan modal
$borrowers = getAllBorrowers($pdo, '', 'active');
$plans = getLoanPlans($pdo);

require_once __DIR__ . '/header.php';
?>

<!-- Title & Action Toolbar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h3 fw-bold mb-1">Loans Portfolio & Accounts</h2>
        <p class="text-muted mb-0">Track loan disbursements, payment terms, amortization, and approval status.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="export.php?type=loans" class="btn btn-outline-success d-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-file-earmark-excel"></i>
            <span>Export CSV</span>
        </a>
        <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#newLoanModal">
            <i class="bi bi-plus-circle-fill"></i> New Loan Application
        </button>
    </div>
</div>

<!-- Filters Bar -->
<div class="card border-0 shadow-sm p-3 mb-4 bg-white">
    <form method="GET" action="loans.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search by loan code, borrower name..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="pending" <?= ($status === 'pending') ? 'selected' : '' ?>>Pending Review</option>
                <option value="disbursed" <?= ($status === 'disbursed') ? 'selected' : '' ?>>Disbursed / Active</option>
                <option value="overdue" <?= ($status === 'overdue') ? 'selected' : '' ?>>Overdue Delinquent</option>
                <option value="completed" <?= ($status === 'completed') ? 'selected' : '' ?>>Completed / Settled</option>
                <option value="rejected" <?= ($status === 'rejected') ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-dark px-3">Filter</button>
            <?php if (!empty($search) || !empty($status)): ?>
                <a href="loans.php" class="btn btn-outline-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Loans Table Card -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Loan Code</th>
                    <th>Borrower</th>
                    <th>Plan / Term</th>
                    <th>Principal</th>
                    <th>Total Payable</th>
                    <th>Remaining Balance</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loans)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-cash-stack fs-1 d-block mb-2 text-secondary opacity-50"></i>
                            No loan records found matching the filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($loans as $loan): ?>
                        <?php 
                            $isOverdue = ($loan['status'] === 'disbursed' && strtotime($loan['due_date']) < strtotime(date('Y-m-d')) && $loan['remaining_balance'] > 0);
                        ?>
                        <tr class="<?= $isOverdue ? 'table-warning' : '' ?>">
                            <td>
                                <span class="fw-bold text-primary"><?= htmlspecialchars($loan['loan_code']) ?></span>
                            </td>
                            <td>
                                <a href="borrower_view.php?id=<?= $loan['borrower_id'] ?>" class="text-decoration-none">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) ?></div>
                                </a>
                                <small class="text-muted"><?= htmlspecialchars($loan['borrower_code']) ?></small>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($loan['plan_name']) ?></div>
                                <small class="text-muted"><?= $loan['interest_rate'] ?>% &middot; <?= $loan['tenure_months'] ?> Months</small>
                            </td>
                            <td class="fw-semibold"><?= formatCurrency($loan['principal_amount']) ?></td>
                            <td><?= formatCurrency($loan['total_payable']) ?></td>
                            <td class="fw-bold text-<?= ($loan['remaining_balance'] > 0) ? 'danger' : 'success' ?>">
                                <?= formatCurrency($loan['remaining_balance']) ?>
                            </td>
                            <td>
                                <div><?= formatDate($loan['due_date']) ?></div>
                                <?php if ($isOverdue): ?>
                                    <span class="badge bg-danger">Overdue</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-status-<?= $loan['status'] ?>">
                                    <?= strtoupper($loan['status']) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="loan_view.php?id=<?= $loan['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-journal-check me-1"></i> Manage & Ledger
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: New Loan Application -->
<!-- ===================================================================== -->
<div class="modal fade" id="newLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate id="loanFormModal">
                <input type="hidden" name="action" value="apply_loan">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-1"></i> Apply for New Loan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Borrower <span class="text-danger">*</span></label>
                            <select name="borrower_id" class="form-select" required>
                                <option value="">-- Choose Borrower --</option>
                                <?php foreach ($borrowers as $b): ?>
                                    <option value="<?= $b['id'] ?>">
                                        <?= htmlspecialchars($b['borrower_code']) ?> - <?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please choose a borrower.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Loan Plan <span class="text-danger">*</span></label>
                            <select name="plan_id" class="form-select" id="planSelect2" required>
                                <option value="" data-rate="0" data-months="0">-- Select Plan --</option>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-rate="<?= $p['interest_rate'] ?>" data-months="<?= $p['tenure_months'] ?>">
                                        <?= htmlspecialchars($p['plan_name']) ?> (<?= $p['interest_rate'] ?>% for <?= $p['tenure_months'] ?> mos)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a loan plan.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Principal Amount (<?= APP_CURRENCY ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="100" min="1000" name="principal_amount" id="principalInput2" class="form-control" placeholder="e.g. 50000" required>
                            <div class="invalid-feedback">Minimum amount is 1,000.00.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Loan Purpose <span class="text-danger">*</span></label>
                            <input type="text" name="purpose" class="form-control" placeholder="e.g. Working capital, home repair" required>
                            <div class="invalid-feedback">Purpose is required.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Collateral / Security Information</label>
                            <input type="text" name="collateral_info" class="form-control" placeholder="e.g. Land Title #1234, Vehicle OR/CR, None">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Guarantor / Co-Maker Name</label>
                            <input type="text" name="guarantor_name" class="form-control" placeholder="e.g. Maria Santos">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Guarantor Phone Number</label>
                            <input type="text" name="guarantor_phone" class="form-control" placeholder="e.g. +63 928 765 4321">
                        </div>

                        <!-- Live Calculation Preview -->
                        <div class="col-12">
                            <div class="p-3 bg-light rounded-3 border">
                                <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-calculator"></i> Repayment Estimate Preview</h6>
                                <div class="row text-center g-2">
                                    <div class="col-4">
                                        <div class="small text-muted">Est. Interest</div>
                                        <div class="fw-bold text-dark" id="previewInterest2"><?= APP_CURRENCY ?> 0.00</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">Total Payable</div>
                                        <div class="fw-bold text-primary" id="previewTotal2"><?= APP_CURRENCY ?> 0.00</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">Monthly Installment</div>
                                        <div class="fw-bold text-success" id="previewMonthly2"><?= APP_CURRENCY ?> 0.00</div>
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

<script>
const planSelect2 = document.getElementById('planSelect2');
const principalInput2 = document.getElementById('principalInput2');
const previewInterest2 = document.getElementById('previewInterest2');
const previewTotal2 = document.getElementById('previewTotal2');
const previewMonthly2 = document.getElementById('previewMonthly2');
const currencySym = '<?= APP_CURRENCY ?> ';

function calculateEstimate2() {
    const selectedOption = planSelect2.options[planSelect2.selectedIndex];
    const rate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
    const months = parseInt(selectedOption.getAttribute('data-months')) || 0;
    const principal = parseFloat(principalInput2.value) || 0;

    if (principal > 0 && rate > 0 && months > 0) {
        const interest = principal * (rate / 100);
        const total = principal + interest;
        const monthly = total / months;

        previewInterest2.textContent = currencySym + interest.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        previewTotal2.textContent = currencySym + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        previewMonthly2.textContent = currencySym + monthly.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    } else {
        previewInterest2.textContent = currencySym + '0.00';
        previewTotal2.textContent = currencySym + '0.00';
        previewMonthly2.textContent = currencySym + '0.00';
    }
}

if (planSelect2 && principalInput2) {
    planSelect2.addEventListener('change', calculateEstimate2);
    principalInput2.addEventListener('input', calculateEstimate2);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
