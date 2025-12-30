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

$error = '';
$success = '';

// Get processing_id from URL
$processing_id = $_GET['id'] ?? 0;

if (!$processing_id) {
    $_SESSION['error'] = "Invalid processing ID";
    header("Location: index.php");
    exit;
}

// Fetch processing details
try {
    $stmt = $conn->prepare("
        SELECT 
            tdp.*,
            c.full_name as customer_name,
            c.phone as customer_phone,
            p.plot_number,
            p.block_number,
            pr.project_name
        FROM title_deed_processing tdp
        LEFT JOIN customers c ON tdp.customer_id = c.customer_id
        LEFT JOIN plots p ON tdp.plot_id = p.plot_id
        LEFT JOIN projects pr ON p.project_id = pr.project_id
        WHERE tdp.processing_id = ? AND tdp.company_id = ?
    ");
    $stmt->execute([$processing_id, $company_id]);
    $processing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$processing) {
        $_SESSION['error'] = "Processing record not found";
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading processing details";
    header("Location: index.php");
    exit;
}

// Handle add cost
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_cost'])) {
    try {
        $cost_category = $_POST['cost_category'];
        $cost_description = $_POST['cost_description'];
        $cost_amount = (float)$_POST['cost_amount'];
        $paid_by = $_POST['paid_by'];
        $payment_date = $_POST['payment_date'] ?? null;
        $receipt_number = $_POST['receipt_number'] ?? null;
        $notes = $_POST['notes'] ?? '';
        
        if ($cost_amount <= 0) {
            throw new Exception("Cost amount must be greater than zero");
        }
        
        $stmt = $conn->prepare("
            INSERT INTO title_deed_costs (
                company_id, processing_id, cost_category, cost_description,
                cost_amount, paid_by, payment_date, receipt_number, notes,
                created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $company_id, $processing_id, $cost_category, $cost_description,
            $cost_amount, $paid_by, $payment_date, $receipt_number, $notes,
            $user_id
        ]);
        
        $success = "Cost added successfully!";
        
    } catch (Exception $e) {
        $error = "Error adding cost: " . $e->getMessage();
    }
}

// Handle delete cost
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cost'])) {
    try {
        $cost_id = (int)$_POST['cost_id'];
        
        $stmt = $conn->prepare("
            DELETE FROM title_deed_costs 
            WHERE cost_id = ? AND company_id = ? AND processing_id = ?
        ");
        $stmt->execute([$cost_id, $company_id, $processing_id]);
        
        $success = "Cost deleted successfully!";
        
    } catch (Exception $e) {
        $error = "Error deleting cost: " . $e->getMessage();
    }
}

// Fetch all costs for this processing
try {
    $costs_stmt = $conn->prepare("
        SELECT 
            tdc.*,
            u.full_name as created_by_name
        FROM title_deed_costs tdc
        LEFT JOIN users u ON tdc.created_by = u.user_id
        WHERE tdc.processing_id = ? AND tdc.company_id = ?
        ORDER BY tdc.created_at DESC
    ");
    $costs_stmt->execute([$processing_id, $company_id]);
    $costs = $costs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $costs = [];
}

// Calculate cost summaries
$total_costs = array_sum(array_column($costs, 'cost_amount'));
$company_paid = array_sum(array_column(array_filter($costs, fn($c) => $c['paid_by'] === 'company'), 'cost_amount'));
$customer_paid = array_sum(array_column(array_filter($costs, fn($c) => $c['paid_by'] === 'customer'), 'cost_amount'));

// Cost categories
$cost_categories = [
    'survey_fees' => 'Survey Fees',
    'legal_fees' => 'Legal Fees',
    'government_fees' => 'Government Fees',
    'municipal_fees' => 'Municipal Fees',
    'ministry_fees' => 'Ministry of Land Fees',
    'documentation' => 'Documentation',
    'processing_fees' => 'Processing Fees',
    'transportation' => 'Transportation',
    'other' => 'Other'
];

$page_title = 'Processing Costs';
require_once '../../includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">

<style>
.processing-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
    height: 100%;
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }

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

.stats-icon {
    font-size: 2.5rem;
    opacity: 0.3;
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
}

.add-cost-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}

.form-section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #007bff;
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
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.paid-by-badge {
    display: inline-block;
    padding: 0.35rem 0.65rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.paid-by-badge.company {
    background: #d1ecf1;
    color: #0c5460;
}

.paid-by-badge.customer {
    background: #d4edda;
    color: #155724;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-dollar-sign text-primary me-2"></i>Processing Costs
                </h1>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="view.php?id=<?php echo $processing_id; ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-eye me-1"></i>View Details
                    </a>
                    <a href="index.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Processing Header -->
        <div class="processing-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-2">
                        <i class="fas fa-file-contract me-2"></i><?php echo htmlspecialchars($processing['processing_number']); ?>
                    </h3>
                    <div class="mb-1">
                        <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($processing['customer_name']); ?>
                        <span class="ms-3"><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($processing['customer_phone']); ?></span>
                    </div>
                    <div>
                        <i class="fas fa-map-marker-alt me-2"></i>
                        Plot <?php echo htmlspecialchars($processing['plot_number']); ?> - 
                        Block <?php echo htmlspecialchars($processing['block_number']); ?> -
                        <?php echo htmlspecialchars($processing['project_name']); ?>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <h4 class="mb-2">Total: TSH <?php echo number_format($processing['total_cost']); ?></h4>
                    <small>Customer Contribution: TSH <?php echo number_format($processing['customer_contribution']); ?></small>
                </div>
            </div>
        </div>

        <!-- Cost Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stats-card primary position-relative">
                    <i class="fas fa-calculator stats-icon"></i>
                    <div class="stats-number">TSH <?php echo number_format($total_costs); ?></div>
                    <div class="stats-label">Total Costs Recorded</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card warning position-relative">
                    <i class="fas fa-building stats-icon"></i>
                    <div class="stats-number">TSH <?php echo number_format($company_paid); ?></div>
                    <div class="stats-label">Company Paid</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card success position-relative">
                    <i class="fas fa-user-check stats-icon"></i>
                    <div class="stats-number">TSH <?php echo number_format($customer_paid); ?></div>
                    <div class="stats-label">Customer Paid</div>
                </div>
            </div>
        </div>

        <!-- Add Cost Form -->
        <div class="add-cost-card">
            <form method="POST">
                <div class="form-section-title">
                    <i class="fas fa-plus-circle me-2"></i>Add New Cost
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Cost Category <span class="text-danger">*</span></label>
                        <select name="cost_category" class="form-select" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($cost_categories as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Cost Amount (TSH) <span class="text-danger">*</span></label>
                        <input type="number" name="cost_amount" class="form-control" step="0.01" min="0" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Paid By <span class="text-danger">*</span></label>
                        <select name="paid_by" class="form-select" required>
                            <option value="">-- Select --</option>
                            <option value="company">Company</option>
                            <option value="customer">Customer</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Cost Description <span class="text-danger">*</span></label>
                        <input type="text" name="cost_description" class="form-control" required>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold">Receipt Number</label>
                        <input type="text" name="receipt_number" class="form-control">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label fw-bold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" name="add_cost" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Add Cost
                    </button>
                </div>
            </form>
        </div>

        <!-- Costs Table -->
        <div class="table-card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Cost Records
                    <span class="badge bg-light text-dark ms-2"><?php echo count($costs); ?> records</span>
                </h5>
            </div>
            <div class="table-responsive">
                <?php if (empty($costs)): ?>
                <div class="empty-state">
                    <i class="fas fa-dollar-sign"></i>
                    <h4>No Costs Recorded</h4>
                    <p class="text-muted">Add your first cost entry using the form above</p>
                </div>
                <?php else: ?>
                <table id="costsTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Paid By</th>
                            <th>Payment Date</th>
                            <th>Receipt #</th>
                            <th>Notes</th>
                            <th>Created By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($costs as $cost): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($cost['created_at'])); ?></td>
                            <td>
                                <strong><?php echo $cost_categories[$cost['cost_category']] ?? ucfirst($cost['cost_category']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($cost['cost_description']); ?></td>
                            <td>
                                <div class="fw-bold text-primary">TSH <?php echo number_format($cost['cost_amount']); ?></div>
                            </td>
                            <td>
                                <span class="paid-by-badge <?php echo $cost['paid_by']; ?>">
                                    <?php echo ucfirst($cost['paid_by']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $cost['payment_date'] ? date('d M Y', strtotime($cost['payment_date'])) : 'N/A'; ?>
                            </td>
                            <td><?php echo $cost['receipt_number'] ? htmlspecialchars($cost['receipt_number']) : '-'; ?></td>
                            <td>
                                <?php if ($cost['notes']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($cost['notes'], 0, 50)); ?><?php echo strlen($cost['notes']) > 50 ? '...' : ''; ?></small>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($cost['created_by_name'] ?? 'System'); ?></small>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this cost?');">
                                    <input type="hidden" name="cost_id" value="<?php echo $cost['cost_id']; ?>">
                                    <button type="submit" name="delete_cost" class="btn btn-sm btn-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary fw-bold">
                            <td colspan="3" class="text-end">TOTAL:</td>
                            <td>TSH <?php echo number_format($total_costs); ?></td>
                            <td colspan="6"></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#costsTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        columnDefs: [
            { orderable: false, targets: 9 }
        ]
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>