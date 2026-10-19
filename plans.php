<?php
/**
 * Loan Plans & Products Management
 * Allows admins to configure financial products: interest rates, tenures, and penalty fees.
 */

$pageTitle = "Loan Plans & Products";
require_once __DIR__ . '/header.php';

$plans = getLoanPlans($pdo, false);

// Metrics calculations
$totalPlans = count($plans);
$activePlans = 0;
$totalLoansCreated = 0;
$totalActiveExposure = 0.0;

foreach ($plans as $p) {
    if ($p['status'] === 'active') $activePlans++;
    $totalLoansCreated += (int)($p['total_loans'] ?? 0);
    $totalActiveExposure += (float)($p['active_exposure'] ?? 0);
}
?>

<!-- Page Header & Action Bar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">
            <i class="bi bi-gear-wide-connected text-primary me-2"></i>Loan Plans & Products
        </h3>
        <p class="text-muted mb-0">Configure financial packages, standard interest rates, payment tenures, and penalty rules.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#newPlanModal">
            <i class="bi bi-plus-circle"></i>
            <span>Add New Plan</span>
        </button>
    </div>
</div>

<!-- Plan Summary KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-primary"><i class="bi bi-collection"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Total Plans</span>
            <h4 class="fw-bold mt-2 mb-0"><?= $totalPlans ?></h4>
            <small class="text-muted mt-1"><?= $activePlans ?> currently active for lending</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-success"><i class="bi bi-check-circle"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Active Products</span>
            <h4 class="fw-bold text-success mt-2 mb-0"><?= $activePlans ?></h4>
            <small class="text-muted mt-1"><?= ($totalPlans - $activePlans) ?> archived / disabled</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-info"><i class="bi bi-file-earmark-text"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Loans Disbursed</span>
            <h4 class="fw-bold text-info mt-2 mb-0"><?= $totalLoansCreated ?></h4>
            <small class="text-muted mt-1">Total loans tagged to plans</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card card-stat border-0 shadow-sm p-3">
            <div class="stat-icon text-warning"><i class="bi bi-shield-check"></i></div>
            <span class="text-muted small text-uppercase fw-semibold">Active Loan Exposure</span>
            <h4 class="fw-bold text-warning mt-2 mb-0"><?= formatCurrency($totalActiveExposure) ?></h4>
            <small class="text-muted mt-1">Outstanding active balance</small>
        </div>
    </div>
</div>

<!-- Plans Grid / Table Card -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i>Configured Lending Schemes</h6>
        <span class="badge bg-light text-dark border"><?= count($plans) ?> Plans Configured</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Plan Name & Description</th>
                    <th>Interest Rate</th>
                    <th>Tenure</th>
                    <th>Late Penalty</th>
                    <th>Loans Issued</th>
                    <th>Active Exposure</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($plans)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                            No loan plans found in database. Create your first plan above.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($plans as $p): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($p['plan_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($p['description'] ?: 'No description provided.') ?></small>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6">
                                    <?= number_format($p['interest_rate'], 2) ?>%
                                </span>
                            </td>
                            <td>
                                <span class="fw-semibold"><?= (int)$p['tenure_months'] ?> Months</span>
                            </td>
                            <td>
                                <span class="text-danger fw-semibold">
                                    <?= number_format($p['penalty_rate'], 2) ?>% / month
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?= (int)($p['total_loans'] ?? 0) ?> Loans
                                </span>
                            </td>
                            <td class="fw-semibold">
                                <?= formatCurrency((float)($p['active_exposure'] ?? 0)) ?>
                            </td>
                            <td>
                                <?php if ($p['status'] === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="bi bi-check2-circle me-1"></i>Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                        <i class="bi bi-pause-circle me-1"></i>Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <form action="actions.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to change the status of this plan?');">
                                    <input type="hidden" name="action" value="toggle_plan">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="current_status" value="<?= $p['status'] ?>">
                                    <?php if ($p['status'] === 'active'): ?>
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Disable Plan">
                                            <i class="bi bi-pause-fill me-1"></i>Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Activate Plan">
                                            <i class="bi bi-play-fill me-1"></i>Activate
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Add New Loan Plan -->
<!-- ===================================================================== -->
<div class="modal fade" id="newPlanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="add_plan">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Create New Loan Plan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Plan Name <span class="text-danger">*</span></label>
                        <input type="text" name="plan_name" class="form-control" placeholder="e.g. Quick Salary Advance (3 Months)" required>
                        <div class="invalid-feedback">Plan name is required.</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Interest Rate (%) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" max="100" name="interest_rate" class="form-control" placeholder="5.00" required>
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="invalid-feedback">Valid interest rate is required.</div>
                        </div>

                        <div class="col-6">
                            <label class="form-label fw-semibold">Tenure (Months) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="1" min="1" max="120" name="tenure_months" class="form-control" placeholder="6" required>
                                <span class="input-group-text">Mos</span>
                            </div>
                            <div class="invalid-feedback">Tenure must be at least 1 month.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Overdue Late Penalty (%) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" name="penalty_rate" class="form-control" value="2.50" required>
                            <span class="input-group-text">% per month</span>
                        </div>
                        <small class="text-muted">Charged on overdue remaining balance after the maturity date.</small>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Description / Terms Summary</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Brief notes about target clients, minimum requirements, or allowed loan amounts..."></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Create Plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
