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

// Handle audit creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_audit') {
        try {
            $conn->beginTransaction();
            
            $sql = "INSERT INTO inventory_audits (
                company_id, audit_number, store_id, audit_date,
                auditor_name, status, created_by
            ) VALUES (?, ?, ?, ?, ?, 'pending', ?)";
            
            $audit_number = 'AUD-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $company_id,
                $audit_number,
                $_POST['store_id'],
                $_POST['audit_date'] ?? date('Y-m-d'),
                $_POST['auditor_name'] ?? $_SESSION['user_name'],
                $_SESSION['user_id']
            ]);
            
            $audit_id = $conn->lastInsertId();
            
            // Create audit lines from current inventory
            $stmt = $conn->prepare("
                INSERT INTO inventory_audit_lines (
                    audit_id, item_id, expected_quantity, actual_quantity, variance
                )
                SELECT ?, item_id, quantity_on_hand, 0, 0
                FROM inventory
                WHERE store_id = ? AND company_id = ?
            ");
            $stmt->execute([$audit_id, $_POST['store_id'], $company_id]);
            
            $conn->commit();
            $success = "Audit created: " . $audit_number;
            header("Location: audit.php?audit_id=" . $audit_id);
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error creating audit: " . $e->getMessage());
            $errors[] = "Error creating audit";
        }
    } elseif ($_POST['action'] === 'update_count') {
        try {
            $stmt = $conn->prepare("
                UPDATE inventory_audit_lines 
                SET actual_quantity = ?,
                    variance = ? - expected_quantity
                WHERE audit_line_id = ?
            ");
            $stmt->execute([
                $_POST['actual_quantity'],
                $_POST['actual_quantity'],
                $_POST['audit_line_id']
            ]);
            $success = "Count updated";
        } catch (PDOException $e) {
            $errors[] = "Error updating count";
        }
    } elseif ($_POST['action'] === 'complete_audit') {
        try {
            $stmt = $conn->prepare("
                UPDATE inventory_audits 
                SET status = 'completed', completed_at = NOW()
                WHERE audit_id = ?
            ");
            $stmt->execute([$_POST['audit_id']]);
            $success = "Audit completed";
        } catch (PDOException $e) {
            $errors[] = "Error completing audit";
        }
    }
}

// Fetch audits
$audit_id = $_GET['audit_id'] ?? null;

if ($audit_id) {
    // Fetch specific audit details
    try {
        $stmt = $conn->prepare("
            SELECT ia.*, s.store_name, s.store_code,
                   COUNT(ial.audit_line_id) as total_items,
                   SUM(CASE WHEN ial.actual_quantity > 0 THEN 1 ELSE 0 END) as counted_items,
                   SUM(ial.variance) as total_variance
            FROM inventory_audits ia
            INNER JOIN stores s ON ia.store_id = s.store_id
            LEFT JOIN inventory_audit_lines ial ON ia.audit_id = ial.audit_id
            WHERE ia.audit_id = ? AND ia.company_id = ?
            GROUP BY ia.audit_id
        ");
        $stmt->execute([$audit_id, $company_id]);
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch audit lines
        $stmt = $conn->prepare("
            SELECT ial.*, i.item_code, i.item_name, i.unit_of_measure, inv.unit_cost
            FROM inventory_audit_lines ial
            INNER JOIN items i ON ial.item_id = i.item_id
            INNER JOIN inventory inv ON ial.item_id = inv.item_id AND inv.store_id = ?
            WHERE ial.audit_id = ?
            ORDER BY i.item_name
        ");
        $stmt->execute([$audit['store_id'], $audit_id]);
        $audit_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error fetching audit: " . $e->getMessage());
        $audit = null;
        $audit_lines = [];
    }
} else {
    // Fetch all audits
    try {
        $stmt = $conn->prepare("
            SELECT ia.*, s.store_name, u.full_name as created_by_name,
                   COUNT(ial.audit_line_id) as total_items
            FROM inventory_audits ia
            INNER JOIN stores s ON ia.store_id = s.store_id
            LEFT JOIN users u ON ia.created_by = u.user_id
            LEFT JOIN inventory_audit_lines ial ON ia.audit_id = ial.audit_id
            WHERE ia.company_id = ?
            GROUP BY ia.audit_id
            ORDER BY ia.created_at DESC
        ");
        $stmt->execute([$company_id]);
        $audits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $audits = [];
    }
}

// Fetch stores
try {
    $stmt = $conn->prepare("SELECT store_id, store_name FROM stores WHERE company_id = ? AND is_active = 1");
    $stmt->execute([$company_id]);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stores = [];
}

$page_title = 'Inventory Audit';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
}
.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-number { font-size: 1.75rem; font-weight: 700; color: #2c3e50; }
.stats-label { color: #6c757d; font-size: 0.875rem; font-weight: 500; text-transform: uppercase; }
.variance-positive { color: #28a745; font-weight: 600; }
.variance-negative { color: #dc3545; font-weight: 600; }
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-clipboard-check text-primary me-2"></i>Inventory Audit
                </h1>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <?php if ($audit_id): ?>
                    <a href="audit.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-list me-1"></i> All Audits
                    </a>
                    <?php else: ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#auditModal">
                        <i class="fas fa-plus-circle me-1"></i> New Audit
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($audit_id && $audit): ?>
            <!-- Audit Details View -->
            <div class="card mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <div class="card-body">
                    <h3><?php echo htmlspecialchars($audit['audit_number']); ?></h3>
                    <p class="mb-0">
                        <strong>Store:</strong> <?php echo htmlspecialchars($audit['store_name']); ?> |
                        <strong>Date:</strong> <?php echo date('M d, Y', strtotime($audit['audit_date'])); ?> |
                        <strong>Auditor:</strong> <?php echo htmlspecialchars($audit['auditor_name']); ?> |
                        <strong>Status:</strong> <?php echo ucfirst($audit['status']); ?>
                    </p>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stats-card primary">
                        <div class="stats-number"><?php echo $audit['total_items']; ?></div>
                        <div class="stats-label">Total Items</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card success">
                        <div class="stats-number"><?php echo $audit['counted_items']; ?></div>
                        <div class="stats-label">Counted</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card warning">
                        <div class="stats-number"><?php echo $audit['total_items'] - $audit['counted_items']; ?></div>
                        <div class="stats-label">Pending</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card <?php echo $audit['total_variance'] == 0 ? 'success' : 'danger'; ?>">
                        <div class="stats-number"><?php echo number_format($audit['total_variance']); ?></div>
                        <div class="stats-label">Total Variance</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Audit Items</h5>
                    <?php if ($audit['status'] === 'pending'): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="complete_audit">
                        <input type="hidden" name="audit_id" value="<?php echo $audit_id; ?>">
                        <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Complete this audit?')">
                            <i class="fas fa-check me-1"></i> Complete Audit
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item Name</th>
                                    <th class="text-center">Expected</th>
                                    <th class="text-center">Actual</th>
                                    <th class="text-center">Variance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($audit_lines as $line): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($line['item_code']); ?></td>
                                    <td><?php echo htmlspecialchars($line['item_name']); ?></td>
                                    <td class="text-center"><?php echo number_format($line['expected_quantity']); ?></td>
                                    <td class="text-center">
                                        <strong><?php echo number_format($line['actual_quantity']); ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <span class="<?php echo $line['variance'] >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                            <?php echo $line['variance'] > 0 ? '+' : ''; ?><?php echo number_format($line['variance']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($audit['status'] === 'pending'): ?>
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="updateCount(<?php echo $line['audit_line_id']; ?>, '<?php echo htmlspecialchars($line['item_name']); ?>', <?php echo $line['actual_quantity']; ?>)">
                                            <i class="fas fa-edit"></i> Count
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Audits List View -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($audits)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-check fa-4x text-muted mb-3"></i>
                        <h4>No Audits Yet</h4>
                        <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#auditModal">
                            <i class="fas fa-plus-circle me-1"></i> Create First Audit
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Audit Number</th>
                                    <th>Store</th>
                                    <th>Date</th>
                                    <th>Auditor</th>
                                    <th>Items</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($audits as $a): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($a['audit_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($a['store_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($a['audit_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($a['auditor_name']); ?></td>
                                    <td><?php echo $a['total_items']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $a['status'] === 'completed' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($a['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="audit.php?audit_id=<?php echo $a['audit_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</section>

<!-- New Audit Modal -->
<div class="modal fade" id="auditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">New Audit</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_audit">
                    <div class="mb-3">
                        <label class="form-label">Store <span class="text-danger">*</span></label>
                        <select name="store_id" class="form-select" required>
                            <option value="">Select Store</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['store_id']; ?>">
                                    <?php echo htmlspecialchars($store['store_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Audit Date</label>
                        <input type="date" name="audit_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Auditor Name</label>
                        <input type="text" name="auditor_name" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Audit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Count Modal -->
<div class="modal fade" id="countModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="countModalTitle">Update Count</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_count">
                    <input type="hidden" name="audit_line_id" id="count_audit_line_id">
                    <div class="mb-3">
                        <label class="form-label">Actual Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="actual_quantity" id="count_actual_quantity" class="form-control" step="0.01" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Count</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateCount(lineId, itemName, currentCount) {
    document.getElementById('countModalTitle').textContent = 'Count: ' + itemName;
    document.getElementById('count_audit_line_id').value = lineId;
    document.getElementById('count_actual_quantity').value = currentCount;
    new bootstrap.Modal(document.getElementById('countModal')).show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>