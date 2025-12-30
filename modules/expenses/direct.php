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
$user_id = $_SESSION['user_id'];

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        // Validation
        if (empty($_POST['expense_date'])) {
            $errors[] = "Expense date is required";
        }
        if (empty($_POST['category_id'])) {
            $errors[] = "Category is required";
        }
        if (empty($_POST['description'])) {
            $errors[] = "Description is required";
        }
        if (empty($_POST['amount']) || $_POST['amount'] <= 0) {
            $errors[] = "Valid amount is required";
        }
        
        if (empty($errors)) {
            try {
                // Calculate total amount
                $amount = floatval($_POST['amount']);
                $tax_amount = floatval($_POST['tax_amount'] ?? 0);
                $total_amount = $amount + $tax_amount;
                
                if ($action === 'create') {
                    // Generate expense number
                    $stmt = $conn->prepare("
                        SELECT COALESCE(MAX(CAST(SUBSTRING(expense_number, 5) AS UNSIGNED)), 0) + 1 as next_num
                        FROM direct_expenses 
                        WHERE company_id = ? AND expense_number LIKE 'EXP-%'
                    ");
                    $stmt->execute([$company_id]);
                    $next_num = $stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
                    $expense_number = 'EXP-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
                    
                    // Get account code from category
                    $stmt = $conn->prepare("SELECT account_code FROM expense_categories WHERE category_id = ?");
                    $stmt->execute([$_POST['category_id']]);
                    $account_code = $stmt->fetch(PDO::FETCH_ASSOC)['account_code'] ?? '';
                    
                    $stmt = $conn->prepare("
                        INSERT INTO direct_expenses (
                            company_id, expense_number, category_id, account_code,
                            expense_date, vendor_id, invoice_number, description,
                            amount, tax_amount, total_amount, currency,
                            payment_method, bank_account_id, payment_reference,
                            status, due_date, project_id, department_id,
                            recurring, recurring_frequency, notes, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $company_id,
                        $expense_number,
                        $_POST['category_id'],
                        $account_code,
                        $_POST['expense_date'],
                        !empty($_POST['vendor_id']) ? $_POST['vendor_id'] : null,
                        $_POST['invoice_number'] ?? null,
                        $_POST['description'],
                        $amount,
                        $tax_amount,
                        $total_amount,
                        'TSH',
                        $_POST['payment_method'] ?? 'bank_transfer',
                        !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null,
                        $_POST['payment_reference'] ?? null,
                        $_POST['status'] ?? 'draft',
                        $_POST['due_date'] ?? null,
                        !empty($_POST['project_id']) ? $_POST['project_id'] : null,
                        !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                        isset($_POST['recurring']) ? 1 : 0,
                        $_POST['recurring_frequency'] ?? null,
                        $_POST['notes'] ?? null,
                        $user_id
                    ]);
                    
                    $success = "Direct expense {$expense_number} created successfully!";
                } else {
                    // Get account code from category
                    $stmt = $conn->prepare("SELECT account_code FROM expense_categories WHERE category_id = ?");
                    $stmt->execute([$_POST['category_id']]);
                    $account_code = $stmt->fetch(PDO::FETCH_ASSOC)['account_code'] ?? '';
                    
                    $stmt = $conn->prepare("
                        UPDATE direct_expenses SET
                            category_id = ?,
                            account_code = ?,
                            expense_date = ?,
                            vendor_id = ?,
                            invoice_number = ?,
                            description = ?,
                            amount = ?,
                            tax_amount = ?,
                            total_amount = ?,
                            payment_method = ?,
                            bank_account_id = ?,
                            payment_reference = ?,
                            status = ?,
                            due_date = ?,
                            project_id = ?,
                            department_id = ?,
                            recurring = ?,
                            recurring_frequency = ?,
                            notes = ?,
                            updated_at = NOW()
                        WHERE expense_id = ? AND company_id = ?
                    ");
                    
                    $stmt->execute([
                        $_POST['category_id'],
                        $account_code,
                        $_POST['expense_date'],
                        !empty($_POST['vendor_id']) ? $_POST['vendor_id'] : null,
                        $_POST['invoice_number'] ?? null,
                        $_POST['description'],
                        $amount,
                        $tax_amount,
                        $total_amount,
                        $_POST['payment_method'] ?? 'bank_transfer',
                        !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null,
                        $_POST['payment_reference'] ?? null,
                        $_POST['status'] ?? 'draft',
                        $_POST['due_date'] ?? null,
                        !empty($_POST['project_id']) ? $_POST['project_id'] : null,
                        !empty($_POST['department_id']) ? $_POST['department_id'] : null,
                        isset($_POST['recurring']) ? 1 : 0,
                        $_POST['recurring_frequency'] ?? null,
                        $_POST['notes'] ?? null,
                        $_POST['expense_id'],
                        $company_id
                    ]);
                    
                    $success = "Direct expense updated successfully!";
                }
            } catch (PDOException $e) {
                error_log("Error saving expense: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'approve') {
        try {
            $stmt = $conn->prepare("
                UPDATE direct_expenses SET 
                    status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE expense_id = ? AND company_id = ?
            ");
            $stmt->execute([$user_id, $_POST['expense_id'], $company_id]);
            $success = "Expense approved successfully!";
        } catch (PDOException $e) {
            error_log("Error approving expense: " . $e->getMessage());
            $errors[] = "Error approving expense";
        }
    } elseif ($action === 'pay') {
        try {
            $stmt = $conn->prepare("
                UPDATE direct_expenses SET 
                    status = 'paid',
                    paid_by = ?,
                    paid_at = NOW()
                WHERE expense_id = ? AND company_id = ? AND status = 'approved'
            ");
            $stmt->execute([$user_id, $_POST['expense_id'], $company_id]);
            $success = "Payment recorded successfully!";
        } catch (PDOException $e) {
            error_log("Error recording payment: " . $e->getMessage());
            $errors[] = "Error recording payment";
        }
    } elseif ($action === 'delete') {
        try {
            $stmt = $conn->prepare("
                DELETE FROM direct_expenses 
                WHERE expense_id = ? AND company_id = ? AND status IN ('draft', 'rejected')
            ");
            $stmt->execute([$_POST['expense_id'], $company_id]);
            $success = "Expense deleted successfully!";
        } catch (PDOException $e) {
            error_log("Error deleting expense: " . $e->getMessage());
            $errors[] = "Error deleting expense";
        }
    }
}

// Fetch filter parameters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_clauses = ["de.company_id = ?"];
$params = [$company_id];

if ($status_filter) {
    $where_clauses[] = "de.status = ?";
    $params[] = $status_filter;
}

if ($category_filter) {
    $where_clauses[] = "de.category_id = ?";
    $params[] = $category_filter;
}

if ($date_from) {
    $where_clauses[] = "de.expense_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "de.expense_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_clauses[] = "(de.expense_number LIKE ? OR de.description LIKE ? OR de.invoice_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch direct expenses
try {
    $stmt = $conn->prepare("
        SELECT 
            de.*,
            ec.category_name,
            s.supplier_name as vendor_name,
            p.project_name,
            d.department_name,
            ba.account_name as bank_account_name,
            creator.full_name as creator_name,
            approver.full_name as approver_name
        FROM direct_expenses de
        LEFT JOIN expense_categories ec ON de.category_id = ec.category_id
        LEFT JOIN suppliers s ON de.vendor_id = s.supplier_id
        LEFT JOIN projects p ON de.project_id = p.project_id
        LEFT JOIN departments d ON de.department_id = d.department_id
        LEFT JOIN bank_accounts ba ON de.bank_account_id = ba.bank_account_id
        LEFT JOIN users creator ON de.created_by = creator.user_id
        LEFT JOIN users approver ON de.approved_by = approver.user_id
        WHERE $where_sql
        ORDER BY de.expense_date DESC, de.created_at DESC
    ");
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching expenses: " . $e->getMessage());
    $expenses = [];
}

// Fetch categories for filter and form
try {
    $stmt = $conn->prepare("
        SELECT category_id, category_name, account_code
        FROM expense_categories
        WHERE company_id = ? AND is_active = 1
        ORDER BY category_name
    ");
    $stmt->execute([$company_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Fetch suppliers
try {
    $stmt = $conn->prepare("
        SELECT supplier_id, supplier_name
        FROM suppliers
        WHERE company_id = ? AND is_active = 1
        ORDER BY supplier_name
    ");
    $stmt->execute([$company_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
}

// Fetch projects
try {
    $stmt = $conn->prepare("
        SELECT project_id, project_name
        FROM projects
        WHERE company_id = ? AND is_active = 1
        ORDER BY project_name
    ");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $projects = [];
}

// Fetch departments
try {
    $stmt = $conn->prepare("
        SELECT department_id, department_name
        FROM departments
        WHERE company_id = ? AND is_active = 1
        ORDER BY department_name
    ");
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// Fetch bank accounts
try {
    $stmt = $conn->prepare("
        SELECT bank_account_id, account_name, bank_name
        FROM bank_accounts
        WHERE company_id = ? AND is_active = 1
        ORDER BY is_default DESC, account_name
    ");
    $stmt->execute([$company_id]);
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bank_accounts = [];
}

// Calculate statistics
$total_expenses = count($expenses);
$total_amount = array_sum(array_column($expenses, 'total_amount'));
$pending_approval = count(array_filter($expenses, fn($e) => $e['status'] === 'pending_approval'));
$approved = count(array_filter($expenses, fn($e) => $e['status'] === 'approved'));
$paid = count(array_filter($expenses, fn($e) => $e['status'] === 'paid'));

// Status badge function
function getStatusBadge($status) {
    $badges = [
        'draft' => 'secondary',
        'pending_approval' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'paid' => 'primary',
        'cancelled' => 'dark'
    ];
    $labels = [
        'draft' => 'Draft',
        'pending_approval' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled'
    ];
    $color = $badges[$status] ?? 'secondary';
    $label = $labels[$status] ?? ucfirst($status);
    return "<span class='badge bg-$color'>$label</span>";
}

$page_title = 'Direct Expenses';
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
.stats-card.danger { border-left-color: #dc3545; }

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

.filter-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
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

.expense-number {
    font-weight: 600;
    color: #007bff;
}

.amount-cell {
    font-weight: 600;
    color: #28a745;
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
                    <i class="fas fa-file-invoice-dollar text-primary me-2"></i>Direct Expenses
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage company direct expenses and bills</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expenseModal">
                        <i class="fas fa-plus-circle me-1"></i> Add Expense
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
                    <div class="stats-number"><?php echo number_format($total_expenses); ?></div>
                    <div class="stats-label">Total Expenses</div>
                    <small class="text-muted">TSH <?php echo number_format($total_amount, 2); ?></small>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format($pending_approval); ?></div>
                    <div class="stats-label">Pending Approval</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($approved); ?></div>
                    <div class="stats-label">Approved</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format($paid); ?></div>
                    <div class="stats-label">Paid</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Expense #, description..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending_approval" <?php echo $status_filter === 'pending_approval' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>" <?php echo $category_filter == $cat['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="direct.php" class="btn btn-secondary">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#expenseModal">
                        <i class="fas fa-plus me-1"></i> New Expense
                    </button>
                </div>
            </form>
        </div>

        <!-- Expenses Table -->
        <div class="table-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Direct Expenses
                    <span class="badge bg-light text-dark ms-2"><?php echo number_format($total_expenses); ?> expenses</span>
                </h5>
            </div>
            <div class="table-responsive">
                <?php if (empty($expenses)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-invoice-dollar fa-4x text-muted mb-3"></i>
                    <h4>No Expenses Found</h4>
                    <p class="text-muted">No expenses match your current filters</p>
                    <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#expenseModal">
                        <i class="fas fa-plus-circle me-1"></i> Add First Expense
                    </button>
                </div>
                <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Expense #</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Vendor</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td>
                                <span class="expense-number"><?php echo htmlspecialchars($expense['expense_number']); ?></span>
                                <?php if ($expense['invoice_number']): ?>
                                    <br><small class="text-muted">INV: <?php echo htmlspecialchars($expense['invoice_number']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></td>
                            <td><?php echo htmlspecialchars($expense['category_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($expense['vendor_name'] ?? 'N/A'); ?></td>
                            <td>
                                <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo htmlspecialchars($expense['description']); ?>
                                </div>
                            </td>
                            <td>
                                <div class="amount-cell">TSH <?php echo number_format($expense['total_amount'], 2); ?></div>
                                <?php if ($expense['tax_amount'] > 0): ?>
                                    <small class="text-muted">Tax: TSH <?php echo number_format($expense['tax_amount'], 2); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo getStatusBadge($expense['status']); ?></td>
                            <td>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($expense['creator_name']); ?><br>
                                    <?php echo date('d M Y', strtotime($expense['created_at'])); ?>
                                </small>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" 
                                            class="btn btn-sm btn-info"
                                            onclick='editExpense(<?php echo json_encode($expense); ?>)'
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <?php if ($expense['status'] === 'pending_approval'): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-success"
                                            onclick="approveExpense(<?php echo $expense['expense_id']; ?>)"
                                            title="Approve">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($expense['status'] === 'approved'): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-primary"
                                            onclick="payExpense(<?php echo $expense['expense_id']; ?>)"
                                            title="Mark as Paid">
                                        <i class="fas fa-dollar-sign"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($expense['status'], ['draft', 'rejected'])): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-danger"
                                            onclick="deleteExpense(<?php echo $expense['expense_id']; ?>, '<?php echo htmlspecialchars($expense['expense_number']); ?>')"
                                            title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5" class="text-end">Total:</th>
                            <th class="amount-cell">TSH <?php echo number_format($total_amount, 2); ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<!-- Add/Edit Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Add Direct Expense
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="expenseForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="expense_id" id="expense_id">

                    <div class="form-section-title">Basic Information</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label required-field">Expense Date</label>
                            <input type="date" name="expense_date" id="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-field">Category</label>
                            <select name="category_id" id="category_id" class="form-select" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>">
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Vendor/Supplier</label>
                            <select name="vendor_id" id="vendor_id" class="form-select">
                                <option value="">-- Select Vendor --</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?php echo $sup['supplier_id']; ?>">
                                        <?php echo htmlspecialchars($sup['supplier_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label required-field">Description</label>
                            <input type="text" name="description" id="description" class="form-control" required placeholder="Brief description of the expense">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Invoice Number</label>
                            <input type="text" name="invoice_number" id="invoice_number" class="form-control" placeholder="e.g., INV-12345">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" id="due_date" class="form-control">
                        </div>
                    </div>

                    <div class="form-section-title">Amount Details</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label required-field">Amount (TSH)</label>
                            <input type="number" name="amount" id="amount" class="form-control" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tax Amount</label>
                            <input type="number" name="tax_amount" id="tax_amount" class="form-control" step="0.01" min="0" value="0" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-section-title">Payment & Assignment</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" id="payment_method" class="form-select">
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bank Account</label>
                            <select name="bank_account_id" id="bank_account_id" class="form-select">
                                <option value="">-- Select Account --</option>
                                <?php foreach ($bank_accounts as $account): ?>
                                    <option value="<?php echo $account['bank_account_id']; ?>">
                                        <?php echo htmlspecialchars($account['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Reference</label>
                            <input type="text" name="payment_reference" id="payment_reference" class="form-control" placeholder="e.g., TRX123456">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Project</label>
                            <select name="project_id" id="project_id" class="form-select">
                                <option value="">-- Select Project --</option>
                                <?php foreach ($projects as $proj): ?>
                                    <option value="<?php echo $proj['project_id']; ?>">
                                        <?php echo htmlspecialchars($proj['project_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Department</label>
                            <select name="department_id" id="department_id" class="form-select">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="draft">Draft</option>
                                <option value="pending_approval">Pending Approval</option>
                                <option value="approved">Approved</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title">Additional Information</div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Additional notes or remarks..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="recurring" id="recurring">
                                <label class="form-check-label" for="recurring">Recurring Expense</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Frequency</label>
                            <select name="recurring_frequency" id="recurring_frequency" class="form-select" disabled>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Enable/disable recurring frequency based on checkbox
document.getElementById('recurring').addEventListener('change', function() {
    document.getElementById('recurring_frequency').disabled = !this.checked;
});

function editExpense(expense) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Direct Expense';
    document.getElementById('formAction').value = 'update';
    document.getElementById('expense_id').value = expense.expense_id;
    document.getElementById('expense_date').value = expense.expense_date;
    document.getElementById('category_id').value = expense.category_id;
    document.getElementById('vendor_id').value = expense.vendor_id || '';
    document.getElementById('description').value = expense.description;
    document.getElementById('invoice_number').value = expense.invoice_number || '';
    document.getElementById('due_date').value = expense.due_date || '';
    document.getElementById('amount').value = expense.amount;
    document.getElementById('tax_amount').value = expense.tax_amount;
    document.getElementById('payment_method').value = expense.payment_method;
    document.getElementById('bank_account_id').value = expense.bank_account_id || '';
    document.getElementById('payment_reference').value = expense.payment_reference || '';
    document.getElementById('project_id').value = expense.project_id || '';
    document.getElementById('department_id').value = expense.department_id || '';
    document.getElementById('status').value = expense.status;
    document.getElementById('notes').value = expense.notes || '';
    document.getElementById('recurring').checked = expense.recurring == 1;
    document.getElementById('recurring_frequency').value = expense.recurring_frequency || 'monthly';
    document.getElementById('recurring_frequency').disabled = expense.recurring != 1;
    
    const modal = new bootstrap.Modal(document.getElementById('expenseModal'));
    modal.show();
}

function approveExpense(expenseId) {
    if (confirm('Approve this expense?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="expense_id" value="${expenseId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function payExpense(expenseId) {
    if (confirm('Mark this expense as paid?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="pay">
            <input type="hidden" name="expense_id" value="${expenseId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteExpense(expenseId, expenseNumber) {
    if (confirm(`Delete expense ${expenseNumber}? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="expense_id" value="${expenseId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset form when modal is closed
document.getElementById('expenseModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('expenseForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-file-invoice-dollar me-2"></i>Add Direct Expense';
    document.getElementById('formAction').value = 'create';
    document.getElementById('expense_id').value = '';
    document.getElementById('recurring_frequency').disabled = true;
    document.getElementById('expense_date').value = '<?php echo date('Y-m-d'); ?>';
});
</script>

<?php 
require_once '../../includes/footer.php';
?>