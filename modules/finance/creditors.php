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

// Fetch suppliers for dropdown
try {
    $stmt = $conn->prepare("
        SELECT supplier_id, supplier_name, supplier_code
        FROM suppliers 
        WHERE company_id = ? AND is_active = 1 
        ORDER BY supplier_name
    ");
    $stmt->execute([$company_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching suppliers: " . $e->getMessage());
    $suppliers = [];
}

// Fetch employees for dropdown
try {
    $stmt = $conn->prepare("
        SELECT e.employee_id, u.full_name, e.employee_number
        FROM employees e
        INNER JOIN users u ON e.user_id = u.user_id
        WHERE e.company_id = ? AND e.is_active = 1
        ORDER BY u.full_name
    ");
    $stmt->execute([$company_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching employees: " . $e->getMessage());
    $employees = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        if (empty($_POST['creditor_type'])) {
            $errors[] = "Creditor type is required";
        }
        if (empty($_POST['creditor_name'])) {
            $errors[] = "Creditor name is required";
        }
        if (empty($_POST['total_amount_owed'])) {
            $errors[] = "Amount owed is required";
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $sql = "INSERT INTO creditors (
                        company_id, creditor_type, supplier_id, employee_id,
                        creditor_name, contact_person, phone, email,
                        total_amount_owed, amount_paid, credit_days, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $company_id,
                        $_POST['creditor_type'],
                        !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null,
                        !empty($_POST['employee_id']) ? $_POST['employee_id'] : null,
                        $_POST['creditor_name'],
                        $_POST['contact_person'] ?? null,
                        $_POST['phone'] ?? null,
                        $_POST['email'] ?? null,
                        floatval($_POST['total_amount_owed']),
                        floatval($_POST['amount_paid'] ?? 0),
                        intval($_POST['credit_days'] ?? 30),
                        $_POST['status'] ?? 'active'
                    ]);
                    
                    $success = "Creditor created successfully!";
                } else {
                    $sql = "UPDATE creditors SET 
                        creditor_type = ?, supplier_id = ?, employee_id = ?,
                        creditor_name = ?, contact_person = ?, phone = ?, email = ?,
                        total_amount_owed = ?, amount_paid = ?, credit_days = ?,
                        status = ?, updated_at = NOW()
                        WHERE creditor_id = ? AND company_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $_POST['creditor_type'],
                        !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null,
                        !empty($_POST['employee_id']) ? $_POST['employee_id'] : null,
                        $_POST['creditor_name'],
                        $_POST['contact_person'] ?? null,
                        $_POST['phone'] ?? null,
                        $_POST['email'] ?? null,
                        floatval($_POST['total_amount_owed']),
                        floatval($_POST['amount_paid'] ?? 0),
                        intval($_POST['credit_days'] ?? 30),
                        $_POST['status'] ?? 'active',
                        $_POST['creditor_id'],
                        $company_id
                    ]);
                    
                    $success = "Creditor updated successfully!";
                }
            } catch (PDOException $e) {
                error_log("Error saving creditor: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        try {
            $stmt = $conn->prepare("DELETE FROM creditors WHERE creditor_id = ? AND company_id = ?");
            $stmt->execute([$_POST['creditor_id'], $company_id]);
            $success = "Creditor deleted successfully!";
        } catch (PDOException $e) {
            error_log("Error deleting creditor: " . $e->getMessage());
            $errors[] = "Error deleting creditor";
        }
    }
}

// Build filter conditions
$where_conditions = ["c.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['creditor_type'])) {
    $where_conditions[] = "c.creditor_type = ?";
    $params[] = $_GET['creditor_type'];
}

if (!empty($_GET['status'])) {
    $where_conditions[] = "c.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(c.creditor_name LIKE ? OR c.contact_person LIKE ? OR c.phone LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch creditors with statistics
try {
    $stmt = $conn->prepare("
        SELECT c.*,
               s.supplier_name,
               u.full_name as employee_name,
               (SELECT COUNT(*) FROM creditor_invoices ci WHERE ci.creditor_id = c.creditor_id) as invoice_count
        FROM creditors c
        LEFT JOIN suppliers s ON c.supplier_id = s.supplier_id
        LEFT JOIN employees e ON c.employee_id = e.employee_id
        LEFT JOIN users u ON e.user_id = u.user_id
        WHERE $where_clause
        ORDER BY c.created_at DESC
    ");
    $stmt->execute($params);
    $creditors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching creditors: " . $e->getMessage());
    $creditors = [];
}

// Calculate statistics
$total_creditors = count($creditors);
$total_owed = 0;
$total_paid = 0;
$overdue_count = 0;

foreach ($creditors as $creditor) {
    $total_owed += $creditor['total_amount_owed'];
    $total_paid += $creditor['amount_paid'];
    if ($creditor['status'] === 'overdue') {
        $overdue_count++;
    }
}

$outstanding_balance = $total_owed - $total_paid;

$page_title = 'Creditors Management (Accounts Payable)';
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
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }

.stats-number {
    font-size: 1.75rem;
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

.creditor-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s;
    border-left: 4px solid #007bff;
}

.creditor-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    transform: translateX(4px);
}

.creditor-card.overdue {
    border-left-color: #dc3545;
}

.creditor-card.disputed {
    border-left-color: #ffc107;
}

.creditor-card.settled {
    border-left-color: #28a745;
    opacity: 0.7;
}

.creditor-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.creditor-name {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2c3e50;
}

.creditor-type {
    font-size: 0.875rem;
    color: #6c757d;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.settled {
    background: #d1ecf1;
    color: #0c5460;
}

.status-badge.overdue {
    background: #f8d7da;
    color: #721c24;
}

.status-badge.disputed {
    background: #fff3cd;
    color: #856404;
}

.amount-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e9ecef;
}

.amount-item {
    text-align: center;
}

.amount-value {
    font-size: 1.1rem;
    font-weight: 700;
}

.amount-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
}

.balance-outstanding {
    color: #dc3545;
}

.balance-paid {
    color: #28a745;
}

.payment-progress {
    height: 6px;
    background: #e9ecef;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.payment-fill {
    height: 100%;
    background: linear-gradient(90deg, #28a745, #20c997);
    transition: width 0.3s;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-invoice-dollar text-danger me-2"></i>Creditors (Accounts Payable)
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage amounts owed to suppliers and creditors</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#creditorModal">
                        <i class="fas fa-plus-circle me-1"></i> Add Creditor
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
                    <div class="stats-number"><?php echo number_format($total_creditors); ?></div>
                    <div class="stats-label">Total Creditors</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number">TSH <?php echo number_format($total_owed / 1000000, 2); ?>M</div>
                    <div class="stats-label">Total Owed</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">TSH <?php echo number_format($total_paid / 1000000, 2); ?>M</div>
                    <div class="stats-label">Total Paid</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">TSH <?php echo number_format($outstanding_balance / 1000000, 2); ?>M</div>
                    <div class="stats-label">Outstanding Balance</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Creditor name, contact, phone..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Creditor Type</label>
                    <select name="creditor_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="supplier" <?php echo (isset($_GET['creditor_type']) && $_GET['creditor_type'] == 'supplier') ? 'selected' : ''; ?>>Supplier</option>
                        <option value="contractor" <?php echo (isset($_GET['creditor_type']) && $_GET['creditor_type'] == 'contractor') ? 'selected' : ''; ?>>Contractor</option>
                        <option value="consultant" <?php echo (isset($_GET['creditor_type']) && $_GET['creditor_type'] == 'consultant') ? 'selected' : ''; ?>>Consultant</option>
                        <option value="employee" <?php echo (isset($_GET['creditor_type']) && $_GET['creditor_type'] == 'employee') ? 'selected' : ''; ?>>Employee</option>
                        <option value="other" <?php echo (isset($_GET['creditor_type']) && $_GET['creditor_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo (isset($_GET['status']) && $_GET['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="settled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'settled') ? 'selected' : ''; ?>>Settled</option>
                        <option value="overdue" <?php echo (isset($_GET['status']) && $_GET['status'] == 'overdue') ? 'selected' : ''; ?>>Overdue</option>
                        <option value="disputed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'disputed') ? 'selected' : ''; ?>>Disputed</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-1"></i> Filter
                    </button>
                    <a href="creditors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Creditors List -->
        <?php if (empty($creditors)): ?>
        <div class="text-center py-5">
            <i class="fas fa-file-invoice-dollar fa-4x text-muted mb-3"></i>
            <h4>No Creditors Found</h4>
            <p class="text-muted">Add your first creditor to start tracking payables</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#creditorModal">
                <i class="fas fa-plus-circle me-1"></i> Add Creditor
            </button>
        </div>
        <?php else: ?>
            <?php foreach ($creditors as $creditor): ?>
            <?php
            $outstanding = $creditor['total_amount_owed'] - $creditor['amount_paid'];
            $payment_percentage = $creditor['total_amount_owed'] > 0 ? ($creditor['amount_paid'] / $creditor['total_amount_owed']) * 100 : 0;
            ?>
            <div class="creditor-card <?php echo $creditor['status']; ?>">
                <div class="creditor-header">
                    <div class="flex-grow-1">
                        <div class="creditor-name">
                            <i class="fas fa-user-tie me-2 text-danger"></i>
                            <?php echo htmlspecialchars($creditor['creditor_name']); ?>
                        </div>
                        <div class="creditor-type">
                            <i class="fas fa-tag me-1"></i>
                            <?php echo ucfirst($creditor['creditor_type']); ?>
                            <?php if (!empty($creditor['supplier_name'])): ?>
                                - <?php echo htmlspecialchars($creditor['supplier_name']); ?>
                            <?php endif; ?>
                            <?php if (!empty($creditor['employee_name'])): ?>
                                - <?php echo htmlspecialchars($creditor['employee_name']); ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($creditor['contact_person'])): ?>
                        <div class="text-muted small mt-1">
                            <i class="fas fa-user me-1"></i>
                            <?php echo htmlspecialchars($creditor['contact_person']); ?>
                            <?php if (!empty($creditor['phone'])): ?>
                                | <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($creditor['phone']); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="status-badge <?php echo $creditor['status']; ?>">
                            <?php echo ucfirst($creditor['status']); ?>
                        </span>
                    </div>
                </div>

                <div class="amount-section">
                    <div class="amount-item">
                        <div class="amount-value">TSH <?php echo number_format($creditor['total_amount_owed']); ?></div>
                        <div class="amount-label">Total Owed</div>
                    </div>
                    <div class="amount-item">
                        <div class="amount-value balance-paid">TSH <?php echo number_format($creditor['amount_paid']); ?></div>
                        <div class="amount-label">Paid</div>
                    </div>
                    <div class="amount-item">
                        <div class="amount-value balance-outstanding">TSH <?php echo number_format($outstanding); ?></div>
                        <div class="amount-label">Outstanding</div>
                    </div>
                    <div class="amount-item">
                        <div class="amount-value"><?php echo $creditor['credit_days']; ?> days</div>
                        <div class="amount-label">Credit Period</div>
                    </div>
                    <div class="amount-item">
                        <div class="amount-value"><?php echo $creditor['invoice_count']; ?></div>
                        <div class="amount-label">Invoices</div>
                    </div>
                </div>

                <div class="payment-progress">
                    <div class="payment-fill" style="width: <?php echo min($payment_percentage, 100); ?>%"></div>
                </div>
                <div class="text-end mt-1">
                    <small class="text-muted"><?php echo number_format($payment_percentage, 1); ?>% paid</small>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="button" 
                            class="btn btn-sm btn-primary"
                            onclick="editCreditor(<?php echo htmlspecialchars(json_encode($creditor)); ?>)">
                        <i class="fas fa-edit me-1"></i> Edit
                    </button>
                    <a href="creditor_invoices.php?creditor_id=<?php echo $creditor['creditor_id']; ?>" 
                       class="btn btn-sm btn-info">
                        <i class="fas fa-file-invoice me-1"></i> Invoices
                    </a>
                    <button type="button" 
                            class="btn btn-sm btn-outline-danger"
                            onclick="deleteCreditor(<?php echo $creditor['creditor_id']; ?>, '<?php echo htmlspecialchars($creditor['creditor_name']); ?>')">
                        <i class="fas fa-trash me-1"></i> Delete
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</section>

<!-- Add/Edit Creditor Modal -->
<div class="modal fade" id="creditorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="modalTitle">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Add Creditor
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="creditorForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="creditor_id" id="creditor_id">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Creditor Type <span class="text-danger">*</span></label>
                            <select name="creditor_type" id="creditor_type" class="form-select" required onchange="toggleCreditorFields()">
                                <option value="">Select Type</option>
                                <option value="supplier">Supplier</option>
                                <option value="contractor">Contractor</option>
                                <option value="consultant">Consultant</option>
                                <option value="employee">Employee</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="supplier_field" style="display:none;">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" id="supplier_id" class="form-select">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['supplier_id']; ?>">
                                        <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="employee_field" style="display:none;">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" id="employee_id" class="form-select">
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo $employee['employee_id']; ?>">
                                        <?php echo htmlspecialchars($employee['full_name']); ?> - 
                                        <?php echo htmlspecialchars($employee['employee_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Creditor Name <span class="text-danger">*</span></label>
                            <input type="text" name="creditor_name" id="creditor_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" id="contact_person" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Amount Owed <span class="text-danger">*</span></label>
                            <input type="number" name="total_amount_owed" id="total_amount_owed" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Amount Paid</label>
                            <input type="number" name="amount_paid" id="amount_paid" class="form-control" step="0.01" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Credit Days</label>
                            <input type="number" name="credit_days" id="credit_days" class="form-control" value="30">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="active">Active</option>
                                <option value="settled">Settled</option>
                                <option value="overdue">Overdue</option>
                                <option value="disputed">Disputed</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Creditor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleCreditorFields() {
    const type = document.getElementById('creditor_type').value;
    document.getElementById('supplier_field').style.display = type === 'supplier' ? 'block' : 'none';
    document.getElementById('employee_field').style.display = type === 'employee' ? 'block' : 'none';
}

function editCreditor(creditor) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Creditor';
    document.getElementById('formAction').value = 'update';
    document.getElementById('creditor_id').value = creditor.creditor_id;
    document.getElementById('creditor_type').value = creditor.creditor_type;
    document.getElementById('creditor_name').value = creditor.creditor_name;
    document.getElementById('contact_person').value = creditor.contact_person || '';
    document.getElementById('phone').value = creditor.phone || '';
    document.getElementById('email').value = creditor.email || '';
    document.getElementById('total_amount_owed').value = creditor.total_amount_owed;
    document.getElementById('amount_paid').value = creditor.amount_paid;
    document.getElementById('credit_days').value = creditor.credit_days;
    document.getElementById('status').value = creditor.status;
    
    if (creditor.supplier_id) {
        document.getElementById('supplier_id').value = creditor.supplier_id;
    }
    if (creditor.employee_id) {
        document.getElementById('employee_id').value = creditor.employee_id;
    }
    
    toggleCreditorFields();
    
    const modal = new bootstrap.Modal(document.getElementById('creditorModal'));
    modal.show();
}

function deleteCreditor(creditorId, creditorName) {
    if (confirm(`Are you sure you want to delete "${creditorName}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="creditor_id" value="${creditorId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Reset form when modal is closed
document.getElementById('creditorModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('creditorForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-file-invoice-dollar me-2"></i>Add Creditor';
    document.getElementById('formAction').value = 'create';
    document.getElementById('creditor_id').value = '';
    toggleCreditorFields();
});
</script>

<?php 
require_once '../../includes/footer.php';
?>