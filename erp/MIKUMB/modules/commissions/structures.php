<?php
define('APP_ACCESS', true);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$db->setCompanyId($_SESSION['company_id']);
$conn = $db->getConnection();
$company_id = $_SESSION['company_id'];

// Only admins can manage structures
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$is_super_admin = isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == 1;

if (!$is_admin && !$is_super_admin) {
    $_SESSION['error'] = "Access denied. Only administrators can manage commission structures.";
    header("Location: index.php");
    exit;
}

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_structure') {
        $structure_name = trim($_POST['structure_name']);
        $structure_code = strtoupper(trim($_POST['structure_code']));
        $commission_type = $_POST['commission_type'];
        $is_tiered = intval($_POST['is_tiered']);
        $base_rate = floatval($_POST['base_rate']);
        $payment_frequency = $_POST['payment_frequency'];
        
        if (empty($structure_name) || empty($structure_code)) {
            $errors[] = "Structure name and code are required";
        } else {
            try {
                $insert_sql = "INSERT INTO commission_structures 
                              (company_id, structure_name, structure_code, commission_type, 
                               is_tiered, base_rate, payment_frequency, is_active, created_by)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->execute([
                    $company_id, $structure_name, $structure_code, $commission_type,
                    $is_tiered, $base_rate, $payment_frequency, $_SESSION['user_id']
                ]);
                
                $success = "Commission structure created successfully!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $errors[] = "Structure code already exists";
                } else {
                    $errors[] = "Error: " . $e->getMessage();
                }
            }
        }
    }
    
    if ($action === 'add_tier') {
        $structure_id = intval($_POST['structure_id']);
        $tier_name = trim($_POST['tier_name']);
        $tier_level = intval($_POST['tier_level']);
        $min_amount = floatval($_POST['min_amount']);
        $max_amount = !empty($_POST['max_amount']) ? floatval($_POST['max_amount']) : null;
        $commission_rate = floatval($_POST['commission_rate']);
        $bonus_rate = floatval($_POST['bonus_rate'] ?? 0);
        
        try {
            $insert_sql = "INSERT INTO commission_tiers 
                          (commission_structure_id, tier_name, tier_level, min_amount, 
                           max_amount, commission_rate, bonus_rate)
                          VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                $structure_id, $tier_name, $tier_level, $min_amount,
                $max_amount, $commission_rate, $bonus_rate
            ]);
            
            $success = "Commission tier added successfully!";
        } catch (PDOException $e) {
            $errors[] = "Error adding tier: " . $e->getMessage();
        }
    }
    
    if ($action === 'toggle_structure') {
        $structure_id = intval($_POST['structure_id']);
        $is_active = intval($_POST['is_active']);
        
        try {
            $update_sql = "UPDATE commission_structures SET is_active = ? 
                          WHERE commission_structure_id = ? AND company_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([$is_active, $structure_id, $company_id]);
            
            $success = "Structure " . ($is_active ? "activated" : "deactivated") . " successfully!";
        } catch (PDOException $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

// Fetch structures
try {
    $structures_sql = "SELECT cs.*, 
                             COUNT(ct.commission_tier_id) as tier_count,
                             u.full_name as created_by_name
                      FROM commission_structures cs
                      LEFT JOIN commission_tiers ct ON cs.commission_structure_id = ct.commission_structure_id
                      LEFT JOIN users u ON cs.created_by = u.user_id
                      WHERE cs.company_id = ?
                      GROUP BY cs.commission_structure_id
                      ORDER BY cs.created_at DESC";
    
    $stmt = $conn->prepare($structures_sql);
    $stmt->execute([$company_id]);
    $structures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $structures = [];
    $errors[] = "Error fetching structures: " . $e->getMessage();
}

$page_title = 'Commission Structures';
require_once '../../includes/header.php';
?>

<style>
.structure-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.1);
    margin-bottom: 25px;
    overflow: hidden;
    border-left: 5px solid #667eea;
}

.structure-card.inactive {
    opacity: 0.6;
    border-left-color: #6c757d;
}

.structure-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.structure-body {
    padding: 25px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.info-box {
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    border-left: 3px solid #667eea;
}

.info-label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    color: #212529;
    font-weight: 600;
}

.tier-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 15px;
    background: #e7f3ff;
    border-left: 4px solid #0066cc;
    border-radius: 6px;
    margin-bottom: 10px;
    font-size: 14px;
}

.tier-level {
    background: #0066cc;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.form-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    padding: 25px;
    margin-bottom: 25px;
}

.form-section-title {
    font-size: 18px;
    font-weight: 700;
    color: #333;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #667eea;
}

.status-toggle {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.status-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 34px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #28a745;
}

input:checked + .slider:before {
    transform: translateX(26px);
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-cogs"></i> Commission Structures</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Commissions</a></li>
                    <li class="breadcrumb-item active">Structures</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h5><i class="fas fa-ban"></i> Errors!</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Create Structure Form -->
        <div class="form-card">
            <div class="form-section-title">
                <i class="fas fa-plus-circle"></i> Create New Structure
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_structure">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Structure Name <span style="color: red;">*</span></label>
                            <input type="text" name="structure_name" class="form-control" required
                                   placeholder="e.g., Standard Sales Commission">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Structure Code <span style="color: red;">*</span></label>
                            <input type="text" name="structure_code" class="form-control" required
                                   placeholder="e.g., STD_SALES">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Commission Type</label>
                            <select name="commission_type" class="form-control">
                                <option value="sales">Sales</option>
                                <option value="referral">Referral</option>
                                <option value="consultant">Consultant</option>
                                <option value="marketing">Marketing</option>
                                <option value="collection">Collection</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Base Rate (%)</label>
                            <input type="number" name="base_rate" class="form-control" 
                                   step="0.01" min="0" max="100" value="5" required>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Payment Frequency</label>
                            <select name="payment_frequency" class="form-control">
                                <option value="immediate">Immediate</option>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="on_completion">On Completion</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_tiered" value="1">
                                Enable Tiered Commission (Different rates for different sales amounts)
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create Structure
                </button>
            </form>
        </div>

        <!-- Existing Structures -->
        <h4 style="margin-bottom: 20px; font-weight: 700;">
            <i class="fas fa-list"></i> Existing Structures (<?php echo count($structures); ?>)
        </h4>

        <?php if (empty($structures)): ?>
        <div class="structure-card">
            <div class="structure-body" style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-cogs" style="font-size: 80px; color: #dee2e6; margin-bottom: 20px;"></i>
                <h4>No Structures Created</h4>
                <p>Create your first commission structure using the form above.</p>
            </div>
        </div>
        <?php else: ?>

        <?php foreach ($structures as $structure): ?>
        <div class="structure-card <?php echo $structure['is_active'] ? '' : 'inactive'; ?>">
            <div class="structure-header">
                <div>
                    <h5 style="margin: 0 0 5px 0; font-weight: 700;">
                        <?php echo htmlspecialchars($structure['structure_name']); ?>
                    </h5>
                    <div style="font-size: 14px; opacity: 0.9;">
                        Code: <?php echo htmlspecialchars($structure['structure_code']); ?>
                    </div>
                </div>
                <div>
                    <?php if ($structure['is_active']): ?>
                        <span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span>
                    <?php else: ?>
                        <span class="badge badge-secondary"><i class="fas fa-ban"></i> Inactive</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="structure-body">
                <div class="info-grid">
                    <div class="info-box">
                        <div class="info-label">Commission Type</div>
                        <div class="info-value"><?php echo ucwords($structure['commission_type']); ?></div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Base Rate</div>
                        <div class="info-value"><?php echo number_format($structure['base_rate'], 2); ?>%</div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Payment Frequency</div>
                        <div class="info-value"><?php echo ucwords(str_replace('_', ' ', $structure['payment_frequency'])); ?></div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Tiered</div>
                        <div class="info-value"><?php echo $structure['is_tiered'] ? 'Yes' : 'No'; ?></div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Tiers</div>
                        <div class="info-value"><?php echo $structure['tier_count']; ?> tier(s)</div>
                    </div>

                    <div class="info-box">
                        <div class="info-label">Created By</div>
                        <div class="info-value"><?php echo htmlspecialchars($structure['created_by_name'] ?? 'System'); ?></div>
                    </div>
                </div>

                <!-- Tiers -->
                <?php if ($structure['is_tiered']): ?>
                <?php
                try {
                    $tiers_sql = "SELECT * FROM commission_tiers 
                                 WHERE commission_structure_id = ?
                                 ORDER BY tier_level";
                    $tiers_stmt = $conn->prepare($tiers_sql);
                    $tiers_stmt->execute([$structure['commission_structure_id']]);
                    $tiers = $tiers_stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $tiers = [];
                }
                ?>

                <?php if (!empty($tiers)): ?>
                <div style="margin-top: 20px;">
                    <h6 style="font-weight: 700; margin-bottom: 15px;">
                        <i class="fas fa-layer-group"></i> Commission Tiers
                    </h6>
                    <?php foreach ($tiers as $tier): ?>
                    <div class="tier-badge">
                        <span class="tier-level"><?php echo $tier['tier_level']; ?></span>
                        <div>
                            <strong><?php echo htmlspecialchars($tier['tier_name']); ?></strong><br>
                            <small style="color: #6c757d;">
                                TZS <?php echo number_format($tier['min_amount'], 0); ?> - 
                                <?php echo $tier['max_amount'] ? 'TZS ' . number_format($tier['max_amount'], 0) : 'Unlimited'; ?> | 
                                Rate: <?php echo number_format($tier['commission_rate'], 2); ?>%
                                <?php if ($tier['bonus_rate'] > 0): ?>
                                    + Bonus: <?php echo number_format($tier['bonus_rate'], 2); ?>%
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Actions -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e9ecef; display: flex; justify-content: space-between; align-items: center;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="toggle_structure">
                        <input type="hidden" name="structure_id" value="<?php echo $structure['commission_structure_id']; ?>">
                        <input type="hidden" name="is_active" value="<?php echo $structure['is_active'] ? 0 : 1; ?>">
                        
                        <label class="status-toggle">
                            <input type="checkbox" <?php echo $structure['is_active'] ? 'checked' : ''; ?>
                                   onchange="this.form.submit()">
                            <span class="slider"></span>
                        </label>
                        <span style="margin-left: 10px; font-weight: 600;">
                            <?php echo $structure['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </form>

                    <?php if ($structure['is_tiered']): ?>
                    <button type="button" class="btn btn-success" 
                            onclick="showAddTierModal(<?php echo $structure['commission_structure_id']; ?>, '<?php echo htmlspecialchars($structure['structure_name']); ?>')">
                        <i class="fas fa-plus"></i> Add Tier
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

    </div>
</section>

<!-- Add Tier Modal -->
<div class="modal fade" id="addTierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> Add Commission Tier
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_tier">
                    <input type="hidden" name="structure_id" id="modal_structure_id">
                    
                    <div class="alert alert-info">
                        <strong>Structure:</strong> <span id="modal_structure_name"></span>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tier Name <span style="color: red;">*</span></label>
                                <input type="text" name="tier_name" class="form-control" 
                                       required placeholder="e.g., Bronze, Silver, Gold">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tier Level <span style="color: red;">*</span></label>
                                <input type="number" name="tier_level" class="form-control" 
                                       min="1" required placeholder="e.g., 1, 2, 3">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Minimum Amount (TZS) <span style="color: red;">*</span></label>
                                <input type="number" name="min_amount" class="form-control" 
                                       step="0.01" required placeholder="0">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Maximum Amount (TZS)</label>
                                <input type="number" name="max_amount" class="form-control" 
                                       step="0.01" placeholder="Leave empty for unlimited">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Commission Rate (%) <span style="color: red;">*</span></label>
                                <input type="number" name="commission_rate" class="form-control" 
                                       step="0.01" min="0" max="100" required>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Bonus Rate (%)</label>
                                <input type="number" name="bonus_rate" class="form-control" 
                                       step="0.01" min="0" max="100" value="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add Tier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAddTierModal(structureId, structureName) {
    document.getElementById('modal_structure_id').value = structureId;
    document.getElementById('modal_structure_name').textContent = structureName;
    $('#addTierModal').modal('show');
}
</script>

<?php require_once '../../includes/footer.php'; ?>