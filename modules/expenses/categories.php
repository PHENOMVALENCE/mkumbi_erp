<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        // Validation
        if (empty($_POST['category_name'])) {
            $errors[] = "Category name is required";
        }
        if (empty($_POST['account_code'])) {
            $errors[] = "Account code is required";
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $stmt = $conn->prepare("
                        INSERT INTO expense_categories (
                            company_id, account_code, category_name, description,
                            parent_category_id, budget_allocation, requires_approval,
                            approval_limit, is_active, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $company_id,
                        $_POST['account_code'],
                        $_POST['category_name'],
                        $_POST['description'] ?? null,
                        !empty($_POST['parent_category_id']) ? $_POST['parent_category_id'] : null,
                        $_POST['budget_allocation'] ?? 0,
                        isset($_POST['requires_approval']) ? 1 : 0,
                        $_POST['approval_limit'] ?? 0,
                        isset($_POST['is_active']) ? 1 : 0,
                        $_SESSION['user_id']
                    ]);
                    
                    $success = "Expense category created successfully!";
                } else {
                    $stmt = $conn->prepare("
                        UPDATE expense_categories SET
                            account_code = ?,
                            category_name = ?,
                            description = ?,
                            parent_category_id = ?,
                            budget_allocation = ?,
                            requires_approval = ?,
                            approval_limit = ?,
                            is_active = ?,
                            updated_at = NOW()
                        WHERE category_id = ? AND company_id = ?
                    ");
                    
                    $stmt->execute([
                        $_POST['account_code'],
                        $_POST['category_name'],
                        $_POST['description'] ?? null,
                        !empty($_POST['parent_category_id']) ? $_POST['parent_category_id'] : null,
                        $_POST['budget_allocation'] ?? 0,
                        isset($_POST['requires_approval']) ? 1 : 0,
                        $_POST['approval_limit'] ?? 0,
                        isset($_POST['is_active']) ? 1 : 0,
                        $_POST['category_id'],
                        $company_id
                    ]);
                    
                    $success = "Expense category updated successfully!";
                }
            } catch (PDOException $e) {
                error_log("Error saving category: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        try {
            // Check if category is being used
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM expense_claim_items 
                WHERE category_id = ?
            ");
            $stmt->execute([$_POST['category_id']]);
            $usage_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($usage_count > 0) {
                // Deactivate instead of delete
                $stmt = $conn->prepare("
                    UPDATE expense_categories 
                    SET is_active = 0 
                    WHERE category_id = ? AND company_id = ?
                ");
                $stmt->execute([$_POST['category_id'], $company_id]);
                $success = "Category deactivated (it's being used in {$usage_count} expense items)";
            } else {
                // Safe to delete
                $stmt = $conn->prepare("
                    DELETE FROM expense_categories 
                    WHERE category_id = ? AND company_id = ?
                ");
                $stmt->execute([$_POST['category_id'], $company_id]);
                $success = "Category deleted successfully!";
            }
        } catch (PDOException $e) {
            error_log("Error deleting category: " . $e->getMessage());
            $errors[] = "Error deleting category";
        }
    }
}

// Fetch expense categories with usage statistics
try {
    $stmt = $conn->prepare("
        SELECT 
            ec.*,
            parent.category_name as parent_name,
            (SELECT COUNT(*) FROM expense_claim_items eci WHERE eci.category_id = ec.category_id) as usage_count,
            (SELECT SUM(total_amount) FROM expense_claim_items eci WHERE eci.category_id = ec.category_id) as total_spent
        FROM expense_categories ec
        LEFT JOIN expense_categories parent ON ec.parent_category_id = parent.category_id
        WHERE ec.company_id = ?
        ORDER BY ec.is_active DESC, ec.category_name
    ");
    $stmt->execute([$company_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Fetch chart of accounts for account code selection
try {
    $stmt = $conn->prepare("
        SELECT account_id, account_code, account_name, account_type
        FROM chart_of_accounts
        WHERE company_id = ? AND account_type = 'expense' AND is_active = 1
        ORDER BY account_code
    ");
    $stmt->execute([$company_id]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching accounts: " . $e->getMessage());
    $accounts = [];
}

// Calculate statistics
$total_categories = count($categories);
$active_categories = count(array_filter($categories, fn($c) => $c['is_active']));
$total_budget = array_sum(array_column($categories, 'budget_allocation'));
$total_spent = array_sum(array_filter(array_column($categories, 'total_spent'), fn($v) => $v !== null));

$page_title = 'Expense Categories';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.table-card .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.25rem 1.5rem;
    border: none;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 1rem;
    white-space: nowrap;
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.action-buttons {
    display: flex;
    gap: 0.25rem;
    flex-wrap: nowrap;
}

.category-name {
    font-weight: 600;
    color: #2c3e50;
}

.account-code {
    font-family: 'Courier New', monospace;
    color: #6c757d;
    font-size: 0.9rem;
}

.usage-badge {
    font-size: 0.75rem;
    padding: 0.35rem 0.65rem;
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.form-section-title {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-tags text-primary me-2"></i>Expense Categories
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage expense categories and classification</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                        <i class="fas fa-plus-circle me-1"></i> Add Category
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Errors:</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($total_categories); ?></div>
                    <div class="stats-label">Total Categories</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($active_categories); ?></div>
                    <div class="stats-label">Active Categories</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">TSH <?php echo number_format($total_budget / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Budget</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format($total_spent / 1000000, 1); ?>M</div>
                    <div class="stats-label">Total Spent</div>
                </div>
            </div>
        </div>

        <!-- Categories Table -->
        <div class="table-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Expense Categories
                    <span class="badge bg-light text-dark ms-2"><?php echo number_format($total_categories); ?> categories</span>
                </h5>
            </div>
            <div class="table-responsive">
                <?php if (empty($categories)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-tags fa-4x text-muted mb-3"></i>
                    <h4>No Categories Found</h4>
                    <p class="text-muted">Start by adding your first expense category</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                        <i class="fas fa-plus-circle me-1"></i> Add Category
                    </button>
                </div>
                <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th>Account Code</th>
                            <th>Parent Category</th>
                            <th>Budget Allocation</th>
                            <th>Total Spent</th>
                            <th>Usage</th>
                            <th>Requires Approval</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $category): ?>
                        <tr>
                            <td>
                                <div class="category-name"><?php echo htmlspecialchars($category['category_name']); ?></div>
                                <?php if ($category['description']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($category['description']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="account-code"><?php echo htmlspecialchars($category['account_code']); ?></span>
                            </td>
                            <td><?php echo $category['parent_name'] ? htmlspecialchars($category['parent_name']) : '<span class="text-muted">None</span>'; ?></td>
                            <td>
                                <?php if ($category['budget_allocation'] > 0): ?>
                                    <strong>TSH <?php echo number_format($category['budget_allocation'], 2); ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($category['total_spent']): ?>
                                    <span class="text-danger">TSH <?php echo number_format($category['total_spent'], 2); ?></span>
                                    <?php if ($category['budget_allocation'] > 0): ?>
                                        <br><small class="text-muted">
                                            <?php echo number_format(($category['total_spent'] / $category['budget_allocation']) * 100, 1); ?>% of budget
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">TSH 0.00</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge usage-badge <?php echo $category['usage_count'] > 0 ? 'bg-info' : 'bg-secondary'; ?>">
                                    <?php echo number_format($category['usage_count']); ?> items
                                </span>
                            </td>
                            <td>
                                <?php if ($category['requires_approval']): ?>
                                    <span class="badge bg-warning">Yes</span>
                                    <?php if ($category['approval_limit'] > 0): ?>
                                        <br><small class="text-muted">&gt; TSH <?php echo number_format($category['approval_limit']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($category['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" 
                                            class="btn btn-sm btn-primary"
                                            onclick='editCategory(<?php echo json_encode($category); ?>)'
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($category['usage_count'] == 0): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-danger"
                                            onclick="deleteCategory(<?php echo $category['category_id']; ?>, '<?php echo htmlspecialchars($category['category_name']); ?>')"
                                            title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php else: ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-warning"
                                            onclick="deactivateCategory(<?php echo $category['category_id']; ?>, '<?php echo htmlspecialchars($category['category_name']); ?>')"
                                            title="Deactivate">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<!-- Add/Edit Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-tags me-2"></i>Add Expense Category
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="categoryForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="category_id" id="category_id">

                    <div class="form-section-title">Basic Information</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label required-field">Category Name</label>
                            <input type="text" name="category_name" id="category_name" class="form-control" required placeholder="e.g., Travel & Transportation">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-field">Account Code</label>
                            <select name="account_code" id="account_code" class="form-select" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo htmlspecialchars($account['account_code']); ?>">
                                        <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2" placeholder="Brief description of this category..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Parent Category</label>
                            <select name="parent_category_id" id="parent_category_id" class="form-select">
                                <option value="">None (Top Level)</option>
                                <?php foreach ($categories as $cat): ?>
                                    <?php if ($cat['is_active']): ?>
                                        <option value="<?php echo $cat['category_id']; ?>">
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Optional: Create sub-categories under a parent</small>
                        </div>
                    </div>

                    <div class="form-section-title">Budget & Approval Settings</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Budget Allocation</label>
                            <input type="number" name="budget_allocation" id="budget_allocation" class="form-control" step="0.01" value="0" min="0" placeholder="0.00">
                            <small class="text-muted">Annual budget for this category</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Approval Limit</label>
                            <input type="number" name="approval_limit" id="approval_limit" class="form-control" step="0.01" value="0" min="0" placeholder="0.00">
                            <small class="text-muted">Amount threshold requiring approval</small>
                        </div>
                    </div>

                    <div class="form-section-title">Settings</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="requires_approval" id="requires_approval">
                                <label class="form-check-label" for="requires_approval">
                                    Requires Approval
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCategory(category) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Expense Category';
    document.getElementById('formAction').value = 'update';
    document.getElementById('category_id').value = category.category_id;
    document.getElementById('category_name').value = category.category_name;
    document.getElementById('account_code').value = category.account_code;
    document.getElementById('description').value = category.description || '';
    document.getElementById('parent_category_id').value = category.parent_category_id || '';
    document.getElementById('budget_allocation').value = category.budget_allocation;
    document.getElementById('approval_limit').value = category.approval_limit;
    document.getElementById('requires_approval').checked = category.requires_approval == 1;
    document.getElementById('is_active').checked = category.is_active == 1;
    
    const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
    modal.show();
}

function deleteCategory(categoryId, categoryName) {
    if (confirm(`Are you sure you want to delete "${categoryName}"? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="category_id" value="${categoryId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deactivateCategory(categoryId, categoryName) {
    if (confirm(`Deactivate "${categoryName}"? It's being used in expense claims and cannot be deleted.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="category_id" value="${categoryId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset form when modal is closed
document.getElementById('categoryModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('categoryForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-tags me-2"></i>Add Expense Category';
    document.getElementById('formAction').value = 'create';
    document.getElementById('category_id').value = '';
    document.getElementById('is_active').checked = true;
});
</script>

<?php 
require_once '../../includes/footer.php';
?>