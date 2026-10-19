<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

try {
    $pdo = getDbConnection();
    $metrics = getDashboardMetrics($pdo);

    // 1. Pending Applications (Awaiting Approval / Disbursement)
    $stmtPending = $pdo->query("SELECT l.*, b.first_name, b.last_name, b.borrower_code, b.phone, p.plan_name, p.interest_rate, p.tenure_months 
                                FROM loans l
                                JOIN borrowers b ON l.borrower_id = b.id
                                JOIN loan_plans p ON l.plan_id = p.id
                                WHERE l.status = 'pending'
                                ORDER BY l.id DESC");
    $pendingLoans = $stmtPending->fetchAll();

    // 2. Active Disbursed Loans (In Repayment)
    $stmtActive = $pdo->query("SELECT l.*, b.first_name, b.last_name, b.borrower_code, b.phone, p.plan_name 
                               FROM loans l
                               JOIN borrowers b ON l.borrower_id = b.id
                               JOIN loan_plans p ON l.plan_id = p.id
                               WHERE l.status = 'disbursed'
                               ORDER BY l.id DESC");
    $activeLoans = $stmtActive->fetchAll();

    // 3. Overdue Delinquent Loans
    $stmtOverdue = $pdo->query("SELECT l.*, b.first_name, b.last_name, b.borrower_code, b.phone, p.plan_name, p.penalty_rate 
                                FROM loans l
                                JOIN borrowers b ON l.borrower_id = b.id
                                JOIN loan_plans p ON l.plan_id = p.id
                                WHERE l.status = 'disbursed' AND l.due_date < CURRENT_DATE AND l.remaining_balance > 0
                                ORDER BY l.due_date ASC");
    $overdueLoans = $stmtOverdue->fetchAll();

    // 4. All Recent Loans (Last 5)
    $stmtRecent = $pdo->query("SELECT l.*, b.first_name, b.last_name, b.borrower_code, p.plan_name 
                               FROM loans l
                               JOIN borrowers b ON l.borrower_id = b.id
                               JOIN loan_plans p ON l.plan_id = p.id
                               ORDER BY l.id DESC LIMIT 5");
    $recentLoans = $stmtRecent->fetchAll();

    // Borrowers and plans for the application modal
    $borrowers = getAllBorrowers($pdo, '', 'active');
    $plans     = getLoanPlans($pdo);

    $dbConnected = true;
} catch (Exception $e) {
    $dbConnected = false;
    $errorMessage = $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>

<?php if (!$dbConnected): ?>
    <div class="card border-danger shadow-sm my-4">
        <div class="card-body p-4 text-center">
            <i class="bi bi-exclamation-triangle-fill text-danger fs-1 mb-3"></i>
            <h4 class="text-danger fw-bold">Database Error</h4>
            <p class="text-muted"><?= htmlspecialchars($errorMessage) ?></p>
            <a href="setup_db.php" class="btn btn-primary px-4">Database Setup</a>
        </div>
    </div>
<?php else: ?>

<!-- Page Title & Quick Actions -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h3 fw-bold mb-1">Portfolio Dashboard</h2>
        <p class="text-muted mb-0">Overview of lending capital, pending applications, active accounts, and collections.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#newLoanModal">
            <i class="bi bi-plus-circle-fill"></i> New Loan Application
        </button>
        <button type="button" class="btn btn-outline-primary d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#newBorrowerModal">
            <i class="bi bi-person-plus-fill"></i> Add Borrower
        </button>
    </div>
</div>

<!-- Primary Financial Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-primary">
            <div class="stat-icon text-primary"><i class="bi bi-cash-stack"></i></div>
            <span class="text-uppercase text-muted fw-bold small">Total Disbursed</span>
            <h3 class="fw-bold my-2 text-dark"><?= formatCurrency($metrics['total_disbursed']) ?></h3>
            <span class="small text-muted">Disbursed capital to date</span>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-success">
            <div class="stat-icon text-success"><i class="bi bi-piggy-bank"></i></div>
            <span class="text-uppercase text-muted fw-bold small">Total Collected</span>
            <h3 class="fw-bold my-2 text-success"><?= formatCurrency($metrics['total_collected']) ?></h3>
            <span class="small text-success fw-semibold"><i class="bi bi-graph-up-arrow"></i> Received Payments</span>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-warning">
            <div class="stat-icon text-warning"><i class="bi bi-hourglass-split"></i></div>
            <span class="text-uppercase text-muted fw-bold small">Outstanding Balance</span>
            <h3 class="fw-bold my-2 text-warning"><?= formatCurrency($metrics['total_outstanding']) ?></h3>
            <span class="small text-muted">Active unpaid principal</span>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm bg-white p-3 h-100 border-start border-4 border-info">
            <div class="stat-icon text-info"><i class="bi bi-people"></i></div>
            <span class="text-uppercase text-muted fw-bold small">Active Borrowers</span>
            <h3 class="fw-bold my-2 text-info"><?= number_format($metrics['active_borrowers']) ?></h3>
            <span class="small text-muted">Accounts in good standing</span>
        </div>
    </div>
</div>

<!-- ===================================================================== -->
<!-- ACTION REQUIRED ALERT: Pending Applications Banner -->
<!-- ===================================================================== -->
<?php if (!empty($pendingLoans)): ?>
    <div class="alert alert-warning border-0 shadow-sm d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 p-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="badge bg-warning text-dark p-2 rounded-3 fs-5">
                <i class="bi bi-bell-fill"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-0 text-dark">
                    <?= count($pendingLoans) ?> Loan Application<?= count($pendingLoans) > 1 ? 's' : '' ?> Pending Approval!
                </h6>
                <small class="text-muted">Review borrower eligibility and approve or reject applications to disburse funds.</small>
            </div>
        </div>
        <div>
            <a href="#tab-pending" class="btn btn-sm btn-dark" onclick="bootstrap.Tab.getOrCreateInstance(document.querySelector('#pending-tab')).show()">
                Review Pending List <i class="bi bi-arrow-down"></i>
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- ===================================================================== -->
<!-- TABBED LOAN SECTIONS: Pending, Active, Overdue, and All Recent -->
<!-- ===================================================================== -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 border-bottom">
        <ul class="nav nav-pills card-header-pills gap-2" id="loanTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= !empty($pendingLoans) ? 'active' : '' ?> d-flex align-items-center gap-2" id="pending-tab" data-bs-toggle="tab" data-bs-target="#tab-pending" type="button" role="tab">
                    <i class="bi bi-clock-history"></i> Pending Applications
                    <span class="badge <?= !empty($pendingLoans) ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= count($pendingLoans) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= empty($pendingLoans) ? 'active' : '' ?> d-flex align-items-center gap-2" id="active-tab" data-bs-toggle="tab" data-bs-target="#tab-active" type="button" role="tab">
                    <i class="bi bi-cash-coin"></i> Active & Disbursed
                    <span class="badge bg-primary"><?= count($activeLoans) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link d-flex align-items-center gap-2" id="overdue-tab" data-bs-toggle="tab" data-bs-target="#tab-overdue" type="button" role="tab">
                    <i class="bi bi-exclamation-triangle"></i> Overdue Delinquent
                    <span class="badge <?= !empty($overdueLoans) ? 'bg-danger' : 'bg-secondary' ?>"><?= count($overdueLoans) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link d-flex align-items-center gap-2" id="all-tab" data-bs-toggle="tab" data-bs-target="#tab-all" type="button" role="tab">
                    <i class="bi bi-journal-text"></i> All Recent Accounts
                </button>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="loanTabsContent">
        <!-- ------------------------------------------------------------- -->
        <!-- TAB 1: PENDING LOANS -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade <?= !empty($pendingLoans) ? 'show active' : '' ?>" id="tab-pending" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Application Code</th>
                            <th>Borrower</th>
                            <th>Plan / Term</th>
                            <th>Requested Principal</th>
                            <th>Calculated Interest</th>
                            <th>Monthly Installment</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingLoans)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
                                    All caught up! No pending loan applications right now.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingLoans as $l): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($l['loan_code']) ?></td>
                                    <td>
                                        <a href="borrower_view.php?id=<?= $l['borrower_id'] ?>" class="text-decoration-none">
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></div>
                                        </a>
                                        <small class="text-muted"><i class="bi bi-telephone"></i> <?= htmlspecialchars($l['phone']) ?></small>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($l['plan_name']) ?></div>
                                        <small class="text-muted"><?= $l['interest_rate'] ?>% for <?= $l['tenure_months'] ?> Mos</small>
                                    </td>
                                    <td class="fw-bold"><?= formatCurrency($l['principal_amount']) ?></td>
                                    <td class="text-muted"><?= formatCurrency($l['interest_amount']) ?></td>
                                    <td class="fw-semibold text-success"><?= formatCurrency($l['monthly_installment']) ?>/mo</td>
                                    <td>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> PENDING REVIEW</span>
                                    </td>
                                    <td class="text-end">
                                        <a href="loan_view.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-check2-circle me-1"></i> Review & Approve
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 2: ACTIVE & DISBURSED LOANS -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade <?= empty($pendingLoans) ? 'show active' : '' ?>" id="tab-active" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Loan Code</th>
                            <th>Borrower</th>
                            <th>Principal</th>
                            <th>Total Payable</th>
                            <th>Paid so far</th>
                            <th>Remaining Balance</th>
                            <th>Due Date</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activeLoans)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="bi bi-cash-stack fs-1 text-secondary opacity-50 d-block mb-2"></i>
                                    No active disbursed loans at the moment.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activeLoans as $l): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($l['loan_code']) ?></td>
                                    <td>
                                        <a href="borrower_view.php?id=<?= $l['borrower_id'] ?>" class="text-decoration-none">
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></div>
                                        </a>
                                        <small class="text-muted"><?= htmlspecialchars($l['borrower_code']) ?></small>
                                    </td>
                                    <td><?= formatCurrency($l['principal_amount']) ?></td>
                                    <td class="fw-semibold"><?= formatCurrency($l['total_payable']) ?></td>
                                    <td class="text-success"><?= formatCurrency($l['total_paid']) ?></td>
                                    <td class="fw-bold text-danger"><?= formatCurrency($l['remaining_balance']) ?></td>
                                    <td><?= formatDate($l['due_date']) ?></td>
                                    <td class="text-end">
                                        <a href="loan_view.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-credit-card-2-front me-1"></i> Record Payment
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 3: OVERDUE DELINQUENT LOANS -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade" id="tab-overdue" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Loan Code</th>
                            <th>Borrower</th>
                            <th>Contact</th>
                            <th>Overdue Since</th>
                            <th>Remaining Balance</th>
                            <th>Penalty Rate</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($overdueLoans)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-success">
                                    <i class="bi bi-shield-check fs-1 d-block mb-2"></i>
                                    Excellent! No overdue delinquent loans detected.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($overdueLoans as $l): ?>
                                <tr class="table-warning">
                                    <td class="fw-bold text-danger"><?= htmlspecialchars($l['loan_code']) ?></td>
                                    <td class="fw-semibold">
                                        <a href="borrower_view.php?id=<?= $l['borrower_id'] ?>" class="text-decoration-none text-dark">
                                            <?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($l['phone']) ?></td>
                                    <td class="text-danger fw-bold"><?= formatDate($l['due_date']) ?></td>
                                    <td class="fw-bold text-danger"><?= formatCurrency($l['remaining_balance']) ?></td>
                                    <td><?= $l['penalty_rate'] ?>% penalty</td>
                                    <td><span class="badge bg-danger">OVERDUE</span></td>
                                    <td class="text-end">
                                        <a href="loan_view.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-danger">
                                            <i class="bi bi-exclamation-circle me-1"></i> Follow Up
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ------------------------------------------------------------- -->
        <!-- TAB 4: ALL RECENT LOANS -->
        <!-- ------------------------------------------------------------- -->
        <div class="tab-pane fade" id="tab-all" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Loan Code</th>
                            <th>Borrower</th>
                            <th>Plan</th>
                            <th>Principal</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th class="text-end">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLoans as $l): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($l['loan_code']) ?></td>
                                <td>
                                    <a href="borrower_view.php?id=<?= $l['borrower_id'] ?>" class="text-decoration-none text-dark">
                                        <?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($l['plan_name']) ?></td>
                                <td><?= formatCurrency($l['principal_amount']) ?></td>
                                <td class="fw-bold text-<?= ($l['remaining_balance'] > 0) ? 'danger' : 'success' ?>">
                                    <?= formatCurrency($l['remaining_balance']) ?>
                                </td>
                                <td>
                                    <span class="badge badge-status-<?= $l['status'] ?>">
                                        <?= strtoupper($l['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="loan_view.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-light border">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL 1: Quick New Loan Application -->
<!-- ===================================================================== -->
<div class="modal fade" id="newLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate id="loanForm">
                <input type="hidden" name="action" value="apply_loan">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-1"></i> New Loan Application</h5>
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
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Loan Plan <span class="text-danger">*</span></label>
                            <select name="plan_id" class="form-select" id="planSelect" required>
                                <option value="" data-rate="0" data-months="0">-- Select Plan --</option>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-rate="<?= $p['interest_rate'] ?>" data-months="<?= $p['tenure_months'] ?>">
                                        <?= htmlspecialchars($p['plan_name']) ?> (<?= $p['interest_rate'] ?>% for <?= $p['tenure_months'] ?> mos)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Principal Amount (<?= APP_CURRENCY ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="100" min="1000" name="principal_amount" id="principalInput" class="form-control" placeholder="e.g. 50000" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Loan Purpose <span class="text-danger">*</span></label>
                            <input type="text" name="purpose" class="form-control" placeholder="e.g. Business expansion, tuition" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Collateral / Security</label>
                            <input type="text" name="collateral_info" class="form-control" placeholder="e.g. Land title, vehicle OR/CR, None">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Guarantor Name</label>
                            <input type="text" name="guarantor_name" class="form-control" placeholder="e.g. Maria Santos">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Guarantor Phone</label>
                            <input type="text" name="guarantor_phone" class="form-control" placeholder="e.g. +63 928 765 4321">
                        </div>

                        <!-- Live Calculation Preview Card -->
                        <div class="col-12">
                            <div class="p-3 bg-light rounded-3 border">
                                <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-calculator"></i> Repayment Estimate Preview</h6>
                                <div class="row text-center g-2">
                                    <div class="col-4">
                                        <div class="small text-muted">Est. Interest</div>
                                        <div class="fw-bold text-dark" id="previewInterest"><?= APP_CURRENCY ?> 0.00</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">Total Payable</div>
                                        <div class="fw-bold text-primary" id="previewTotal"><?= APP_CURRENCY ?> 0.00</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">Monthly Installment</div>
                                        <div class="fw-bold text-success" id="previewMonthly"><?= APP_CURRENCY ?> 0.00</div>
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
<!-- MODAL 2: Register New Borrower -->
<!-- ===================================================================== -->
<div class="modal fade" id="newBorrowerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="add_borrower">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-1"></i> Register New Borrower</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Mobile Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" placeholder="+63 9XX XXX XXXX" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Monthly Income (<?= APP_CURRENCY ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="500" min="1" name="monthly_income" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Residential Address <span class="text-danger">*</span></label>
                            <input type="text" name="address" class="form-control" required>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Borrower</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Dynamic Loan Calculator for New Loan Modal
const planSelect = document.getElementById('planSelect');
const principalInput = document.getElementById('principalInput');
const previewInterest = document.getElementById('previewInterest');
const previewTotal = document.getElementById('previewTotal');
const previewMonthly = document.getElementById('previewMonthly');
const currencySym = '<?= APP_CURRENCY ?> ';

function calculateEstimate() {
    const selectedOption = planSelect.options[planSelect.selectedIndex];
    const rate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
    const months = parseInt(selectedOption.getAttribute('data-months')) || 0;
    const principal = parseFloat(principalInput.value) || 0;

    if (principal > 0 && rate > 0 && months > 0) {
        const interest = principal * (rate / 100);
        const total = principal + interest;
        const monthly = total / months;

        previewInterest.textContent = currencySym + interest.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        previewTotal.textContent = currencySym + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        previewMonthly.textContent = currencySym + monthly.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    } else {
        previewInterest.textContent = currencySym + '0.00';
        previewTotal.textContent = currencySym + '0.00';
        previewMonthly.textContent = currencySym + '0.00';
    }
}

if (planSelect && principalInput) {
    planSelect.addEventListener('change', calculateEstimate);
    principalInput.addEventListener('input', calculateEstimate);
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
