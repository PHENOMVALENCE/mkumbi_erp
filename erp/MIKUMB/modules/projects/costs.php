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

// Get project ID from URL
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if (!$project_id) {
    header('Location: index.php');
    exit;
}

// Fetch project details
try {
    $project_sql = "SELECT * FROM projects WHERE project_id = ? AND company_id = ?";
    $project_stmt = $conn->prepare($project_sql);
    $project_stmt->execute([$project_id, $company_id]);
    $project = $project_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching project: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

// Initialize variables
$errors = [];
$success = '';

// Handle form submission (Add/Edit Cost)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cost_id = isset($_POST['cost_id']) ? intval($_POST['cost_id']) : 0;
    
    // Validate inputs
    if (empty($_POST['cost_category'])) {
        $errors[] = "Cost category is required";
    }
    if (empty($_POST['cost_description'])) {
        $errors[] = "Cost description is required";
    }
    if (empty($_POST['cost_amount']) || $_POST['cost_amount'] <= 0) {
        $errors[] = "Valid cost amount is required";
    }
    if (empty($_POST['cost_date'])) {
        $errors[] = "Cost date is required";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Handle file upload
            $attachment_path = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../uploads/project_costs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $file_name = 'cost_' . time() . '_' . uniqid() . '.' . $file_ext;
                $attachment_path = $upload_dir . $file_name;
                move_uploaded_file($_FILES['attachment']['tmp_name'], $attachment_path);
                $attachment_path = 'uploads/project_costs/' . $file_name;
            }
            
            if ($cost_id > 0) {
                // Update existing cost
                $sql = "UPDATE project_costs SET 
                        cost_category = ?,
                        cost_description = ?,
                        cost_amount = ?,
                        cost_date = ?,
                        receipt_number = ?,
                        remarks = ?";
                
                $params = [
                    $_POST['cost_category'],
                    $_POST['cost_description'],
                    $_POST['cost_amount'],
                    $_POST['cost_date'],
                    $_POST['receipt_number'] ?? null,
                    $_POST['remarks'] ?? null
                ];
                
                if ($attachment_path) {
                    $sql .= ", attachment_path = ?";
                    $params[] = $attachment_path;
                }
                
                $sql .= " WHERE cost_id = ? AND company_id = ?";
                $params[] = $cost_id;
                $params[] = $company_id;
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                
                $success = "Cost updated successfully!";
            } else {
                // Insert new cost
                $sql = "INSERT INTO project_costs (
                    company_id, project_id, cost_category, cost_description,
                    cost_amount, cost_date, receipt_number, attachment_path,
                    remarks, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $company_id,
                    $project_id,
                    $_POST['cost_category'],
                    $_POST['cost_description'],
                    $_POST['cost_amount'],
                    $_POST['cost_date'],
                    $_POST['receipt_number'] ?? null,
                    $attachment_path,
                    $_POST['remarks'] ?? null,
                    $_SESSION['user_id']
                ]);
                
                $success = "Cost added successfully!";
            }
            
            // Update project operational costs
            $update_project_sql = "UPDATE projects 
                                   SET total_operational_costs = (
                                       SELECT COALESCE(SUM(cost_amount), 0) 
                                       FROM project_costs 
                                       WHERE project_id = ?
                                   )
                                   WHERE project_id = ? AND company_id = ?";
            $update_stmt = $conn->prepare($update_project_sql);
            $update_stmt->execute([$project_id, $project_id, $company_id]);
            
            $conn->commit();
            
            // Redirect to clear POST data
            header("Location: costs.php?project_id=$project_id&success=1");
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error saving cost: " . $e->getMessage());
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Handle delete
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    try {
        $conn->beginTransaction();
        
        // Delete the cost
        $delete_sql = "DELETE FROM project_costs WHERE cost_id = ? AND company_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->execute([$delete_id, $company_id]);
        
        // Update project operational costs
        $update_project_sql = "UPDATE projects 
                               SET total_operational_costs = (
                                   SELECT COALESCE(SUM(cost_amount), 0) 
                                   FROM project_costs 
                                   WHERE project_id = ?
                               )
                               WHERE project_id = ? AND company_id = ?";
        $update_stmt = $conn->prepare($update_project_sql);
        $update_stmt->execute([$project_id, $project_id, $company_id]);
        
        $conn->commit();
        
        header("Location: costs.php?project_id=$project_id&deleted=1");
        exit;
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Error deleting cost: " . $e->getMessage());
    }
}

// Fetch all costs for this project
try {
    $costs_sql = "SELECT pc.*, u.full_name as created_by_name
                  FROM project_costs pc
                  LEFT JOIN users u ON pc.created_by = u.user_id
                  WHERE pc.project_id = ? AND pc.company_id = ?
                  ORDER BY pc.cost_date DESC, pc.created_at DESC";
    $costs_stmt = $conn->prepare($costs_sql);
    $costs_stmt->execute([$project_id, $company_id]);
    $costs = $costs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals by category
    $totals_sql = "SELECT cost_category, SUM(cost_amount) as category_total
                   FROM project_costs
                   WHERE project_id = ? AND company_id = ?
                   GROUP BY cost_category";
    $totals_stmt = $conn->prepare($totals_sql);
    $totals_stmt->execute([$project_id, $company_id]);
    $category_totals = $totals_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Calculate grand total
    $grand_total_sql = "SELECT COALESCE(SUM(cost_amount), 0) as grand_total
                        FROM project_costs
                        WHERE project_id = ? AND company_id = ?";
    $grand_stmt = $conn->prepare($grand_total_sql);
    $grand_stmt->execute([$project_id, $company_id]);
    $grand_total = $grand_stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error fetching costs: " . $e->getMessage());
    $costs = [];
    $category_totals = [];
    $grand_total = 0;
}

// Cost categories
$cost_categories = [
    'land_purchase' => 'Land Purchase',
    'survey' => 'Survey & Mapping',
    'legal_fees' => 'Legal Fees',
    'title_processing' => 'Title Processing',
    'development' => 'Infrastructure Development',
    'marketing' => 'Marketing & Sales',
    'consultation' => 'Professional Services',
    'other' => 'Other'
];

$page_title = 'Project Costs - ' . $project['project_name'];
require_once '../../includes/header.php';

// Show success message
if (isset($_GET['success'])) {
    $success = "Cost saved successfully!";
}
if (isset($_GET['deleted'])) {
    $success = "Cost deleted successfully!";
}
?>

<style>
.cost-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.cost-card-header {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
}

.cost-card-header i {
    margin-right: 0.5rem;
    color: #007bff;
}

.stat-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
    margin-bottom: 1.5rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.875rem;
    opacity: 0.9;
}

.table-hover tbody tr:hover {
    background-color: #f8f9fa;
}

.category-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 500;
}

.btn-add {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    border: none;
    color: white;
    font-weight: 600;
}

.btn-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(17, 153, 142, 0.4);
    color: white;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-dollar-sign text-primary me-2"></i>Project Costs
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    <?php echo htmlspecialchars($project['project_name']); ?> - 
                    <span class="text-primary"><?php echo htmlspecialchars($project['project_code']); ?></span>
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button type="button" class="btn btn-add me-2" data-bs-toggle="modal" data-bs-target="#costModal">
                        <i class="fas fa-plus me-1"></i> Add Cost
                    </button>
                    <a href="view.php?id=<?php echo $project_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Project
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Display Errors -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Errors:</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Display Success -->
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-box">
                    <div class="stat-value">TSH <?php echo number_format($grand_total, 2); ?></div>
                    <div class="stat-label">Total Project Costs</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stat-value"><?php echo count($costs); ?></div>
                    <div class="stat-label">Total Cost Entries</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stat-value">TSH <?php echo number_format($project['land_purchase_price'] ?? 0, 2); ?></div>
                    <div class="stat-label">Land Purchase Price</div>
                </div>
            </div>
        </div>

        <!-- Costs by Category -->
        <div class="cost-card mb-4">
            <div class="cost-card-header">
                <i class="fas fa-chart-pie"></i>
                <span>Costs by Category</span>
            </div>
            <div class="row">
                <?php foreach ($cost_categories as $cat_key => $cat_name): ?>
                    <?php $cat_total = $category_totals[$cat_key] ?? 0; ?>
                    <div class="col-md-6 mb-3">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                            <span class="fw-bold"><?php echo $cat_name; ?></span>
                            <span class="text-primary fw-bold">TSH <?php echo number_format($cat_total, 2); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Costs Table -->
        <div class="cost-card">
            <div class="cost-card-header">
                <i class="fas fa-list"></i>
                <span>All Cost Entries</span>
            </div>

            <?php if (empty($costs)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No costs recorded yet. Click "Add Cost" to add your first entry.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Receipt No.</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($costs as $index => $cost): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo date('d M Y', strtotime($cost['cost_date'])); ?></td>
                                <td>
                                    <span class="category-badge bg-primary text-white">
                                        <?php echo $cost_categories[$cost['cost_category']] ?? $cost['cost_category']; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($cost['cost_description']); ?></td>
                                <td class="fw-bold text-primary">TSH <?php echo number_format($cost['cost_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($cost['receipt_number'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($cost['created_by_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <button type="button" 
                                            class="btn btn-sm btn-warning me-1" 
                                            onclick="editCost(<?php echo htmlspecialchars(json_encode($cost)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($cost['attachment_path']): ?>
                                    <a href="../../<?php echo htmlspecialchars($cost['attachment_path']); ?>" 
                                       target="_blank" 
                                       class="btn btn-sm btn-info me-1">
                                        <i class="fas fa-paperclip"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-danger" 
                                            onclick="confirmDelete(<?php echo $cost['cost_id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">Grand Total:</th>
                                <th class="text-primary">TSH <?php echo number_format($grand_total, 2); ?></th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
</section>

<!-- Add/Edit Cost Modal -->
<div class="modal fade" id="costModal" tabindex="-1" aria-labelledby="costModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="costModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Add Project Cost
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="cost_id" id="cost_id">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Cost Category <span class="text-danger">*</span></label>
                            <select name="cost_category" id="cost_category" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($cost_categories as $key => $name): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Cost Date <span class="text-danger">*</span></label>
                            <input type="date" name="cost_date" id="cost_date" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Cost Description <span class="text-danger">*</span></label>
                            <textarea name="cost_description" id="cost_description" class="form-control" rows="2" required></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Cost Amount (TSH) <span class="text-danger">*</span></label>
                            <input type="number" name="cost_amount" id="cost_amount" class="form-control" step="0.01" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Receipt Number</label>
                            <input type="text" name="receipt_number" id="receipt_number" class="form-control">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Attachment (Receipt/Invoice)</label>
                            <input type="file" name="attachment" id="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="text-muted">PDF, JPG, PNG files only</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" id="remarks" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Cost
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCost(cost) {
    document.getElementById('cost_id').value = cost.cost_id;
    document.getElementById('cost_category').value = cost.cost_category;
    document.getElementById('cost_date').value = cost.cost_date;
    document.getElementById('cost_description').value = cost.cost_description;
    document.getElementById('cost_amount').value = cost.cost_amount;
    document.getElementById('receipt_number').value = cost.receipt_number || '';
    document.getElementById('remarks').value = cost.remarks || '';
    
    document.getElementById('costModalLabel').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Project Cost';
    
    var modal = new bootstrap.Modal(document.getElementById('costModal'));
    modal.show();
}

function confirmDelete(costId) {
    if (confirm('Are you sure you want to delete this cost entry? This action cannot be undone.')) {
        window.location.href = 'costs.php?project_id=<?php echo $project_id; ?>&delete_id=' + costId;
    }
}

// Reset modal when closed
document.getElementById('costModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('cost_id').value = '';
    document.getElementById('cost_category').value = '';
    document.getElementById('cost_date').value = '';
    document.getElementById('cost_description').value = '';
    document.getElementById('cost_amount').value = '';
    document.getElementById('receipt_number').value = '';
    document.getElementById('remarks').value = '';
    document.getElementById('costModalLabel').innerHTML = '<i class="fas fa-plus-circle me-2"></i>Add Project Cost';
});
</script>

<?php 
require_once '../../includes/footer.php';
?>