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

// Handle movement creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'create_movement') {
        if (empty($_POST['item_id']) || empty($_POST['movement_type']) || empty($_POST['quantity'])) {
            $errors[] = "All required fields must be filled";
        }
        
        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                
                $movement_type = $_POST['movement_type'];
                $item_id = $_POST['item_id'];
                $from_store = $_POST['from_store_id'] ?? null;
                $to_store = $_POST['to_store_id'] ?? null;
                $quantity = floatval($_POST['quantity']);
                
                // Insert movement record
                $sql = "INSERT INTO inventory_movements (
                    company_id, item_id, movement_type, from_store_id, to_store_id,
                    quantity, unit_cost, reference_number, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $company_id,
                    $item_id,
                    $movement_type,
                    $from_store,
                    $to_store,
                    $quantity,
                    floatval($_POST['unit_cost'] ?? 0),
                    $_POST['reference_number'] ?? null,
                    $_POST['notes'] ?? null,
                    $_SESSION['user_id']
                ]);
                
                // Update inventory based on movement type
                if ($movement_type === 'in' || $movement_type === 'purchase') {
                    // Add to destination store
                    $stmt = $conn->prepare("
                        UPDATE inventory 
                        SET quantity_on_hand = quantity_on_hand + ?,
                            last_updated = NOW()
                        WHERE item_id = ? AND store_id = ?
                    ");
                    $stmt->execute([$quantity, $item_id, $to_store]);
                    
                } elseif ($movement_type === 'out' || $movement_type === 'sale') {
                    // Remove from source store
                    $stmt = $conn->prepare("
                        UPDATE inventory 
                        SET quantity_on_hand = quantity_on_hand - ?,
                            last_updated = NOW()
                        WHERE item_id = ? AND store_id = ?
                    ");
                    $stmt->execute([$quantity, $item_id, $from_store]);
                    
                } elseif ($movement_type === 'transfer') {
                    // Remove from source
                    $stmt = $conn->prepare("
                        UPDATE inventory 
                        SET quantity_on_hand = quantity_on_hand - ?,
                            last_updated = NOW()
                        WHERE item_id = ? AND store_id = ?
                    ");
                    $stmt->execute([$quantity, $item_id, $from_store]);
                    
                    // Add to destination
                    $stmt = $conn->prepare("
                        UPDATE inventory 
                        SET quantity_on_hand = quantity_on_hand + ?,
                            last_updated = NOW()
                        WHERE item_id = ? AND store_id = ?
                    ");
                    $stmt->execute([$quantity, $item_id, $to_store]);
                    
                } elseif ($movement_type === 'adjustment') {
                    // Can be positive or negative
                    $stmt = $conn->prepare("
                        UPDATE inventory 
                        SET quantity_on_hand = quantity_on_hand + ?,
                            last_updated = NOW()
                        WHERE item_id = ? AND store_id = ?
                    ");
                    $adjustment = $_POST['adjustment_type'] === 'increase' ? $quantity : -$quantity;
                    $stmt->execute([$adjustment, $item_id, $to_store ?? $from_store]);
                }
                
                $conn->commit();
                $success = "Movement recorded successfully!";
                
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("Error creating movement: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Build filter conditions
$where_conditions = ["im.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['movement_type'])) {
    $where_conditions[] = "im.movement_type = ?";
    $params[] = $_GET['movement_type'];
}

if (!empty($_GET['store_id'])) {
    $where_conditions[] = "(im.from_store_id = ? OR im.to_store_id = ?)";
    $params[] = $_GET['store_id'];
    $params[] = $_GET['store_id'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "im.movement_date >= ?";
    $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "im.movement_date <= ?";
    $params[] = $_GET['date_to'] . ' 23:59:59';
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch movements
try {
    $stmt = $conn->prepare("
        SELECT 
            im.*,
            i.item_code,
            i.item_name,
            i.unit_of_measure,
            sf.store_name as from_store_name,
            st.store_name as to_store_name,
            u.full_name as created_by_name
        FROM inventory_movements im
        INNER JOIN items i ON im.item_id = i.item_id
        LEFT JOIN stores sf ON im.from_store_id = sf.store_id
        LEFT JOIN stores st ON im.to_store_id = st.store_id
        LEFT JOIN users u ON im.created_by = u.user_id
        WHERE $where_clause
        ORDER BY im.movement_date DESC, im.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching movements: " . $e->getMessage());
    $movements = [];
}

// Fetch stores and items for dropdowns
try {
    $stmt = $conn->prepare("SELECT store_id, store_code, store_name FROM stores WHERE company_id = ? AND is_active = 1 ORDER BY store_name");
    $stmt->execute([$company_id]);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("SELECT item_id, item_code, item_name, unit_of_measure FROM items WHERE company_id = ? ORDER BY item_name");
    $stmt->execute([$company_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stores = [];
    $items = [];
}

// Calculate statistics
$stats = [
    'total_movements' => count($movements),
    'in' => 0,
    'out' => 0,
    'transfers' => 0
];

foreach ($movements as $movement) {
    if (in_array($movement['movement_type'], ['in', 'purchase'])) {
        $stats['in'] += $movement['quantity'];
    } elseif (in_array($movement['movement_type'], ['out', 'sale'])) {
        $stats['out'] += $movement['quantity'];
    } elseif ($movement['movement_type'] === 'transfer') {
        $stats['transfers']++;
    }
}

$page_title = 'Inventory Movements';
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

.stats-card:hover { transform: translateY(-4px); }
.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.danger { border-left-color: #dc3545; }
.stats-card.info { border-left-color: #17a2b8; }

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
}

.movement-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.movement-badge.in { background: #d4edda; color: #155724; }
.movement-badge.out { background: #f8d7da; color: #721c24; }
.movement-badge.transfer { background: #d1ecf1; color: #0c5460; }
.movement-badge.adjustment { background: #fff3cd; color: #856404; }
</style>

<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-exchange-alt text-primary me-2"></i>Inventory Movements
                </h1>
                <p class="text-muted small mb-0 mt-1">Track stock movements and transfers</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="stock.php" class="btn btn-outline-info me-2">
                        <i class="fas fa-boxes me-1"></i> Stock Levels
                    </a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#movementModal">
                        <i class="fas fa-plus-circle me-1"></i> New Movement
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
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

        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format($stats['total_movements']); ?></div>
                    <div class="stats-label">Total Movements</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format($stats['in']); ?></div>
                    <div class="stats-label">Items In</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format($stats['out']); ?></div>
                    <div class="stats-label">Items Out</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format($stats['transfers']); ?></div>
                    <div class="stats-label">Transfers</div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <select name="movement_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="in" <?php echo ($_GET['movement_type'] ?? '') == 'in' ? 'selected' : ''; ?>>Stock In</option>
                            <option value="out" <?php echo ($_GET['movement_type'] ?? '') == 'out' ? 'selected' : ''; ?>>Stock Out</option>
                            <option value="transfer" <?php echo ($_GET['movement_type'] ?? '') == 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                            <option value="adjustment" <?php echo ($_GET['movement_type'] ?? '') == 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="date_from" class="form-control" value="<?php echo $_GET['date_from'] ?? ''; ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="date_to" class="form-control" value="<?php echo $_GET['date_to'] ?? ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="store_id" class="form-select">
                            <option value="">All Stores</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['store_id']; ?>" <?php echo ($_GET['store_id'] ?? '') == $store['store_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['store_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Item</th>
                                <th>From</th>
                                <th>To</th>
                                <th class="text-center">Quantity</th>
                                <th>Reference</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $movement): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i', strtotime($movement['movement_date'])); ?></td>
                                <td>
                                    <span class="movement-badge <?php echo $movement['movement_type']; ?>">
                                        <?php echo ucfirst($movement['movement_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($movement['item_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($movement['item_code']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($movement['from_store_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($movement['to_store_name'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <strong><?php echo number_format($movement['quantity']); ?></strong>
                                    <small class="text-muted"><?php echo htmlspecialchars($movement['unit_of_measure']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($movement['reference_number'] ?? '-'); ?></td>
                                <td><small><?php echo htmlspecialchars($movement['created_by_name']); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- Movement Modal -->
<div class="modal fade" id="movementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Record Movement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_movement">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Movement Type <span class="text-danger">*</span></label>
                            <select name="movement_type" id="movement_type" class="form-select" required onchange="toggleFields()">
                                <option value="">Select Type</option>
                                <option value="in">Stock In</option>
                                <option value="out">Stock Out</option>
                                <option value="transfer">Transfer</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Item <span class="text-danger">*</span></label>
                            <select name="item_id" class="form-select" required>
                                <option value="">Select Item</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>">
                                        <?php echo htmlspecialchars($item['item_code']); ?> - <?php echo htmlspecialchars($item['item_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="from_store_field">
                            <label class="form-label">From Store</label>
                            <select name="from_store_id" class="form-select">
                                <option value="">Select Store</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['store_id']; ?>">
                                        <?php echo htmlspecialchars($store['store_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="to_store_field">
                            <label class="form-label">To Store</label>
                            <select name="to_store_id" class="form-select">
                                <option value="">Select Store</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['store_id']; ?>">
                                        <?php echo htmlspecialchars($store['store_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit Cost</label>
                            <input type="number" name="unit_cost" class="form-control" step="0.01">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Record Movement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleFields() {
    const type = document.getElementById('movement_type').value;
    const fromField = document.getElementById('from_store_field');
    const toField = document.getElementById('to_store_field');
    
    if (type === 'in') {
        fromField.style.display = 'none';
        toField.style.display = 'block';
    } else if (type === 'out') {
        fromField.style.display = 'block';
        toField.style.display = 'none';
    } else {
        fromField.style.display = 'block';
        toField.style.display = 'block';
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>