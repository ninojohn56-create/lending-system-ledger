<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$pdo = getDbConnection();
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$borrowers = getAllBorrowers($pdo, $search, $status);

require_once __DIR__ . '/header.php';
?>

<!-- Title & Filter Toolbar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h3 fw-bold mb-1">Borrowers Directory</h2>
        <p class="text-muted mb-0">Manage customer profiles, credit ratings, and view account loan history.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="export.php?type=borrowers" class="btn btn-outline-success d-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-file-earmark-excel"></i>
            <span>Export CSV</span>
        </a>
        <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addBorrowerModal">
            <i class="bi bi-person-plus-fill"></i> Register Borrower
        </button>
    </div>
</div>

<!-- Search & Filtering Card -->
<div class="card border-0 shadow-sm p-3 mb-4 bg-white">
    <form method="GET" action="borrowers.php" class="row g-2 align-items-center">
        <div class="col-md-6 col-lg-5">
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search by name, code, phone, or email..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        <div class="col-md-4 col-lg-3">
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="active" <?= ($status === 'active') ? 'selected' : '' ?>>Active Only</option>
                <option value="inactive" <?= ($status === 'inactive') ? 'selected' : '' ?>>Inactive Only</option>
                <option value="blacklisted" <?= ($status === 'blacklisted') ? 'selected' : '' ?>>Blacklisted</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-dark px-3">Filter</button>
            <?php if (!empty($search) || !empty($status)): ?>
                <a href="borrowers.php" class="btn btn-outline-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Borrowers Table Card -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Borrower Name</th>
                    <th>Contact Info</th>
                    <th>Monthly Income</th>
                    <th>Loans Profile</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($borrowers)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-2 text-secondary opacity-50"></i>
                            No borrowers match your search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($borrowers as $b): ?>
                        <tr>
                            <td>
                                <a href="borrower_view.php?id=<?= $b['id'] ?>" class="badge bg-light text-primary border fw-bold text-decoration-none">
                                    <?= htmlspecialchars($b['borrower_code']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="borrower_view.php?id=<?= $b['id'] ?>" class="text-decoration-none">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?></div>
                                </a>
                                <small class="text-muted"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars(substr($b['address'], 0, 35)) . (strlen($b['address']) > 35 ? '...' : '') ?></small>
                            </td>
                            <td>
                                <div><i class="bi bi-envelope text-muted me-1"></i> <?= htmlspecialchars($b['email']) ?></div>
                                <small class="text-muted"><i class="bi bi-telephone text-muted me-1"></i> <?= htmlspecialchars($b['phone']) ?></small>
                            </td>
                            <td>
                                <span class="fw-semibold"><?= formatCurrency($b['monthly_income']) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary me-1"><?= $b['total_loans'] ?> Total Loans</span>
                                <?php if ($b['total_outstanding'] > 0): ?>
                                    <div class="small text-danger fw-bold mt-1">
                                        <?= formatCurrency($b['total_outstanding']) ?> balance
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($b['status'] === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                <?php elseif ($b['status'] === 'blacklisted'): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Blacklisted</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="borrower_view.php?id=<?= $b['id'] ?>" class="btn btn-outline-primary" title="View 360 Dossier">
                                        <i class="bi bi-person-badge"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-secondary edit-borrower-btn" 
                                        data-id="<?= $b['id'] ?>"
                                        data-first="<?= htmlspecialchars($b['first_name']) ?>"
                                        data-last="<?= htmlspecialchars($b['last_name']) ?>"
                                        data-email="<?= htmlspecialchars($b['email']) ?>"
                                        data-phone="<?= htmlspecialchars($b['phone']) ?>"
                                        data-income="<?= $b['monthly_income'] ?>"
                                        data-address="<?= htmlspecialchars($b['address']) ?>"
                                        data-status="<?= $b['status'] ?>"
                                        data-bs-toggle="modal" data-bs-target="#editBorrowerModal" title="Edit Profile">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger delete-borrower-btn" 
                                        data-id="<?= $b['id'] ?>"
                                        data-name="<?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?>"
                                        data-bs-toggle="modal" data-bs-target="#deleteBorrowerModal" title="Delete Borrower">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Add Borrower -->
<!-- ===================================================================== -->
<div class="modal fade" id="addBorrowerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="add_borrower">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-1"></i> Register New Borrower</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required>
                            <div class="invalid-feedback">First name is required.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required>
                            <div class="invalid-feedback">Last name is required.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                            <div class="invalid-feedback">Valid email required.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Mobile Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" placeholder="+63 9XX XXX XXXX" required>
                            <div class="invalid-feedback">Phone is required.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Monthly Income (<?= APP_CURRENCY ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="500" min="1" name="monthly_income" class="form-control" required>
                            <div class="invalid-feedback">Valid income required.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Complete Address <span class="text-danger">*</span></label>
                            <input type="text" name="address" class="form-control" required>
                            <div class="invalid-feedback">Address is required.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Register Borrower</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Edit Borrower -->
<!-- ===================================================================== -->
<div class="modal fade" id="editBorrowerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" class="needs-validation" novalidate id="editBorrowerForm">
                <input type="hidden" name="action" value="edit_borrower">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-1"></i> Edit Borrower Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="edit_first" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="edit_last" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Mobile Phone <span class="text-danger">*</span></label>
                            <input type="text" name="phone" id="edit_phone" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Monthly Income (<?= APP_CURRENCY ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="500" min="1" name="monthly_income" id="edit_income" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="blacklisted">Blacklisted</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Complete Address <span class="text-danger">*</span></label>
                            <input type="text" name="address" id="edit_address" class="form-control" required>
                        </div>
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

<!-- ===================================================================== -->
<!-- MODAL: Delete Confirmation -->
<!-- ===================================================================== -->
<div class="modal fade" id="deleteBorrowerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST">
                <input type="hidden" name="action" value="delete_borrower">
                <input type="hidden" name="id" id="delete_borrower_id">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <i class="bi bi-trash text-danger fs-1 mb-3"></i>
                    <p class="mb-1">Are you sure you want to delete borrower:</p>
                    <h5 class="fw-bold text-dark" id="delete_borrower_name"></h5>
                    <div class="alert alert-warning text-start small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i> Borrowers with active loans cannot be deleted. Borrowers with historical completed loans will be safely archived to protect audit logs.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4">Yes, Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Populate Edit Modal
document.querySelectorAll('.edit-borrower-btn').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('edit_id').value = button.getAttribute('data-id');
        document.getElementById('edit_first').value = button.getAttribute('data-first');
        document.getElementById('edit_last').value = button.getAttribute('data-last');
        document.getElementById('edit_email').value = button.getAttribute('data-email');
        document.getElementById('edit_phone').value = button.getAttribute('data-phone');
        document.getElementById('edit_income').value = button.getAttribute('data-income');
        document.getElementById('edit_address').value = button.getAttribute('data-address');
        document.getElementById('edit_status').value = button.getAttribute('data-status');
    });
});

// Populate Delete Modal
document.querySelectorAll('.delete-borrower-btn').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('delete_borrower_id').value = button.getAttribute('data-id');
        document.getElementById('delete_borrower_name').textContent = button.getAttribute('data-name');
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
