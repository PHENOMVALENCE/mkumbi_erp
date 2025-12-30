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

// Get plot ID from URL
$plot_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$plot_id) {
    $_SESSION['error'] = "Invalid plot ID";
    header('Location: index.php');
    exit;
}

// Initialize variables
$errors = [];
$plot = null;

// ==================== FETCH PLOT DATA ====================
try {
    $plot_sql = "SELECT p.*, pr.project_id, pr.project_name, pr.project_code
                 FROM plots p
                 LEFT JOIN projects pr ON p.project_id = pr.project_id
                 WHERE p.plot_id = ? AND p.company_id = ?";
    $plot_stmt = $conn->prepare($plot_sql);
    $plot_stmt->execute([$plot_id, $company_id]);
    $plot = $plot_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plot) {
        $_SESSION['error'] = "Plot not found";
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching plot: " . $e->getMessage());
    $_SESSION['error'] = "Error loading plot details";
    header('Location: index.php');
    exit;
}

// ==================== CHECK FOR DEPENDENCIES ====================
$has_dependencies = false;
$dependency_details = [];

try {
    // Check for reservations
    $reservation_sql = "SELECT COUNT(*) as count FROM reservations 
                        WHERE plot_id = ? AND company_id = ?";
    $reservation_stmt = $conn->prepare($reservation_sql);
    $reservation_stmt->execute([$plot_id, $company_id]);
    $reservation_count = $reservation_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($reservation_count > 0) {
        $has_dependencies = true;
        $dependency_details[] = [
            'type' => 'Reservations/Sales',
            'count' => $reservation_count,
            'icon' => 'fas fa-shopping-cart',
            'color' => 'danger'
        ];
    }

    // Check for payments (through reservations)
    $payment_sql = "SELECT COUNT(p.payment_id) as count 
                    FROM payments p
                    INNER JOIN reservations r ON p.reservation_id = r.reservation_id
                    WHERE r.plot_id = ? AND p.company_id = ?";
    $payment_stmt = $conn->prepare($payment_sql);
    $payment_stmt->execute([$plot_id, $company_id]);
    $payment_count = $payment_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($payment_count > 0) {
        $has_dependencies = true;
        $dependency_details[] = [
            'type' => 'Payments',
            'count' => $payment_count,
            'icon' => 'fas fa-money-bill-wave',
            'color' => 'warning'
        ];
    }

    // Check for contracts
    $contract_sql = "SELECT COUNT(c.contract_id) as count 
                     FROM plot_contracts c
                     INNER JOIN reservations r ON c.reservation_id = r.reservation_id
                     WHERE r.plot_id = ? AND c.company_id = ?";
    $contract_stmt = $conn->prepare($contract_sql);
    $contract_stmt->execute([$plot_id, $company_id]);
    $contract_count = $contract_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($contract_count > 0) {
        $has_dependencies = true;
        $dependency_details[] = [
            'type' => 'Contracts',
            'count' => $contract_count,
            'icon' => 'fas fa-file-contract',
            'color' => 'info'
        ];
    }

    // Check for quotations
    $quotation_sql = "SELECT COUNT(*) as count FROM sales_quotations 
                      WHERE plot_id = ? AND company_id = ?";
    $quotation_stmt = $conn->prepare($quotation_sql);
    $quotation_stmt->execute([$plot_id, $company_id]);
    $quotation_count = $quotation_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($quotation_count > 0) {
        $has_dependencies = true;
        $dependency_details[] = [
            'type' => 'Quotations',
            'count' => $quotation_count,
            'icon' => 'fas fa-file-invoice',
            'color' => 'secondary'
        ];
    }

    // Check for service requests
    $service_sql = "SELECT COUNT(*) as count FROM service_requests 
                    WHERE plot_id = ? AND company_id = ?";
    $service_stmt = $conn->prepare($service_sql);
    $service_stmt->execute([$plot_id, $company_id]);
    $service_count = $service_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($service_count > 0) {
        $has_dependencies = true;
        $dependency_details[] = [
            'type' => 'Service Requests',
            'count' => $service_count,
            'icon' => 'fas fa-tools',
            'color' => 'primary'
        ];
    }

} catch (PDOException $e) {
    error_log("Error checking dependencies: " . $e->getMessage());
}

// ==================== HANDLE DELETION ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    
    // Verify token to prevent CSRF
    if (!isset($_POST['delete_token']) || $_POST['delete_token'] !== $plot_id) {
        $errors[] = "Invalid deletion request";
    }

    // Check if force delete is allowed
    $force_delete = isset($_POST['force_delete']) && $_POST['force_delete'] === '1';

    // If has dependencies and not force delete, show error
    if ($has_dependencies && !$force_delete) {
        $errors[] = "Cannot delete plot with existing dependencies. Check the 'Force Delete' option to proceed.";
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $project_id = $plot['project_id'];

            // If force delete, remove dependencies first
            if ($force_delete && $has_dependencies) {
                
                // Get all reservation IDs for this plot
                $res_ids_sql = "SELECT reservation_id FROM reservations 
                               WHERE plot_id = ? AND company_id = ?";
                $res_ids_stmt = $conn->prepare($res_ids_sql);
                $res_ids_stmt->execute([$plot_id, $company_id]);
                $reservation_ids = $res_ids_stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($reservation_ids)) {
                    $placeholders = implode(',', array_fill(0, count($reservation_ids), '?'));
                    
                    // Delete payment vouchers
                    $delete_vouchers_sql = "DELETE FROM payment_vouchers 
                                           WHERE reservation_id IN ($placeholders) 
                                           AND company_id = ?";
                    $delete_vouchers_stmt = $conn->prepare($delete_vouchers_sql);
                    $delete_vouchers_stmt->execute(array_merge($reservation_ids, [$company_id]));

                    // Delete payments
                    $delete_payments_sql = "DELETE FROM payments 
                                           WHERE reservation_id IN ($placeholders) 
                                           AND company_id = ?";
                    $delete_payments_stmt = $conn->prepare($delete_payments_sql);
                    $delete_payments_stmt->execute(array_merge($reservation_ids, [$company_id]));

                    // Delete payment schedules
                    $delete_schedules_sql = "DELETE FROM payment_schedules 
                                            WHERE reservation_id IN ($placeholders) 
                                            AND company_id = ?";
                    $delete_schedules_stmt = $conn->prepare($delete_schedules_sql);
                    $delete_schedules_stmt->execute(array_merge($reservation_ids, [$company_id]));

                    // Delete contracts
                    $delete_contracts_sql = "DELETE FROM plot_contracts 
                                            WHERE reservation_id IN ($placeholders) 
                                            AND company_id = ?";
                    $delete_contracts_stmt = $conn->prepare($delete_contracts_sql);
                    $delete_contracts_stmt->execute(array_merge($reservation_ids, [$company_id]));

                    // Delete commissions
                    $delete_commissions_sql = "DELETE FROM commissions 
                                              WHERE reservation_id IN ($placeholders) 
                                              AND company_id = ?";
                    $delete_commissions_stmt = $conn->prepare($delete_commissions_sql);
                    $delete_commissions_stmt->execute(array_merge($reservation_ids, [$company_id]));

                    // Delete reservations
                    $delete_reservations_sql = "DELETE FROM reservations 
                                               WHERE reservation_id IN ($placeholders) 
                                               AND company_id = ?";
                    $delete_reservations_stmt = $conn->prepare($delete_reservations_sql);
                    $delete_reservations_stmt->execute(array_merge($reservation_ids, [$company_id]));
                }

                // Delete quotations
                $delete_quotations_sql = "DELETE FROM sales_quotations 
                                         WHERE plot_id = ? AND company_id = ?";
                $delete_quotations_stmt = $conn->prepare($delete_quotations_sql);
                $delete_quotations_stmt->execute([$plot_id, $company_id]);

                // Delete service requests
                $delete_services_sql = "DELETE FROM service_requests 
                                       WHERE plot_id = ? AND company_id = ?";
                $delete_services_stmt = $conn->prepare($delete_services_sql);
                $delete_services_stmt->execute([$plot_id, $company_id]);
            }

            // Delete the plot
            $delete_plot_sql = "DELETE FROM plots 
                               WHERE plot_id = ? AND company_id = ?";
            $delete_plot_stmt = $conn->prepare($delete_plot_sql);
            $delete_plot_stmt->execute([$plot_id, $company_id]);

            // Update project plot counts
            if ($project_id) {
                $update_project_sql = "UPDATE projects 
                                      SET total_plots = (
                                          SELECT COUNT(*) FROM plots 
                                          WHERE project_id = ?
                                      ),
                                      available_plots = (
                                          SELECT COUNT(*) FROM plots 
                                          WHERE project_id = ? AND status = 'available'
                                      ),
                                      reserved_plots = (
                                          SELECT COUNT(*) FROM plots 
                                          WHERE project_id = ? AND status = 'reserved'
                                      ),
                                      sold_plots = (
                                          SELECT COUNT(*) FROM plots 
                                          WHERE project_id = ? AND status = 'sold'
                                      )
                                      WHERE project_id = ? AND company_id = ?";
                
                $update_project_stmt = $conn->prepare($update_project_sql);
                $update_project_stmt->execute([
                    $project_id,
                    $project_id,
                    $project_id,
                    $project_id,
                    $project_id,
                    $company_id
                ]);
            }

            $conn->commit();

            $_SESSION['success'] = "Plot '" . $plot['plot_number'] . "' has been deleted successfully" . 
                                  ($force_delete ? " along with all related records" : "");
            header('Location: index.php');
            exit;

        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error deleting plot: " . $e->getMessage());
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = 'Delete Plot - ' . htmlspecialchars($plot['plot_number']);
require_once '../../includes/header.php';
?>

<style>
.delete-container {
    max-width: 800px;
    margin: 2rem auto;
}

.danger-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.15);
    border: 2px solid #dc3545;
    overflow: hidden;
}

.danger-header {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    color: white;
    padding: 1.5rem;
    text-align: center;
}

.danger-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.danger-body {
    padding: 2rem;
}

.plot-info-box {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 1.5rem;
    margin: 1.5rem 0;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e9ecef;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #495057;
}

.info-value {
    color: #2c3e50;
    font-weight: 500;
}

.warning-box {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-left: 4px solid #ffc107;
    border-radius: 6px;
    padding: 1rem;
    margin: 1rem 0;
}

.danger-box {
    background: #f8d7da;
    border: 1px solid #dc3545;
    border-left: 4px solid #dc3545;
    border-radius: 6px;
    padding: 1rem;
    margin: 1rem 0;
}

.dependency-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-left: 4px solid;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    transition: transform 0.2s;
}

.dependency-card:hover {
    transform: translateX(5px);
}

.dependency-card.danger {
    border-left-color: #dc3545;
}

.dependency-card.warning {
    border-left-color: #ffc107;
}

.dependency-card.info {
    border-left-color: #17a2b8;
}

.dependency-card.primary {
    border-left-color: #007bff;
}

.dependency-card.secondary {
    border-left-color: #6c757d;
}

.dependency-count {
    font-size: 1.5rem;
    font-weight: 700;
}

.confirmation-box {
    background: #fff;
    border: 2px solid #dc3545;
    border-radius: 8px;
    padding: 1.5rem;
    margin: 1.5rem 0;
}

.checkbox-label {
    font-weight: 600;
    color: #dc3545;
}

.btn-delete {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    border: none;
    color: white;
    padding: 0.75rem 2rem;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.btn-delete:hover {
    background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 53, 69, 0.4);
    color: white;
}

.btn-delete:disabled {
    background: #6c757d;
    cursor: not-allowed;
    opacity: 0.6;
}
</style>

<div class="delete-container">
    <div class="danger-card">
        <!-- Header -->
        <div class="danger-header">
            <div class="danger-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h2 class="mb-0">Delete Plot</h2>
            <p class="mb-0 mt-2">This action cannot be undone!</p>
        </div>

        <!-- Body -->
        <div class="danger-body">
            
            <!-- Display Errors -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Cannot Delete Plot</h5>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Plot Information -->
            <h5 class="mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>Plot Information</h5>
            <div class="plot-info-box">
                <div class="info-row">
                    <span class="info-label">Plot Number:</span>
                    <span class="info-value"><strong><?php echo htmlspecialchars($plot['plot_number']); ?></strong></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Project:</span>
                    <span class="info-value"><?php echo htmlspecialchars($plot['project_name']); ?> (<?php echo htmlspecialchars($plot['project_code']); ?>)</span>
                </div>
                <?php if ($plot['block_number']): ?>
                <div class="info-row">
                    <span class="info-label">Block Number:</span>
                    <span class="info-value"><?php echo htmlspecialchars($plot['block_number']); ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Plot Area:</span>
                    <span class="info-value"><?php echo number_format($plot['area'], 2); ?> m²</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Selling Price:</span>
                    <span class="info-value">TSH <?php echo number_format($plot['selling_price'], 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="info-value">
                        <span class="badge bg-<?php 
                            echo $plot['status'] === 'available' ? 'success' : 
                                ($plot['status'] === 'reserved' ? 'warning' : 
                                ($plot['status'] === 'sold' ? 'info' : 'danger')); 
                        ?>">
                            <?php echo strtoupper($plot['status']); ?>
                        </span>
                    </span>
                </div>
            </div>

            <!-- Warning Message -->
            <div class="warning-box">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Warning:</strong> You are about to permanently delete this plot from the system. 
                This action will remove all plot information and cannot be undone.
            </div>

            <!-- Dependencies Check -->
            <?php if ($has_dependencies): ?>
            <div class="danger-box">
                <h6 class="mb-3">
                    <i class="fas fa-link me-2"></i>
                    <strong>This plot has dependencies!</strong>
                </h6>
                <p class="mb-3">The following records are linked to this plot and will also be deleted if you proceed with force delete:</p>
                
                <div class="row">
                    <?php foreach ($dependency_details as $dependency): ?>
                    <div class="col-md-6 mb-2">
                        <div class="dependency-card <?php echo $dependency['color']; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="<?php echo $dependency['icon']; ?> me-2 text-<?php echo $dependency['color']; ?>"></i>
                                    <strong><?php echo $dependency['type']; ?></strong>
                                </div>
                                <div class="dependency-count text-<?php echo $dependency['color']; ?>">
                                    <?php echo $dependency['count']; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="alert alert-danger mt-3 mb-0">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <strong>Deleting this plot will permanently remove all the records listed above!</strong>
                </div>
            </div>
            <?php endif; ?>

            <!-- Deletion Form -->
            <form method="POST" id="deleteForm" onsubmit="return confirmDeletion();">
                <input type="hidden" name="delete_token" value="<?php echo $plot_id; ?>">
                
                <div class="confirmation-box">
                    <h6 class="mb-3"><i class="fas fa-check-circle me-2"></i>Confirmation Required</h6>
                    
                    <?php if ($has_dependencies): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" 
                               type="checkbox" 
                               name="force_delete" 
                               id="force_delete"
                               value="1"
                               onchange="toggleDeleteButton()">
                        <label class="form-check-label checkbox-label" for="force_delete">
                            I understand that this will delete the plot and ALL related records permanently
                        </label>
                    </div>
                    <?php endif; ?>

                    <div class="form-check">
                        <input class="form-check-input" 
                               type="checkbox" 
                               name="confirm_check" 
                               id="confirm_check"
                               required
                               onchange="toggleDeleteButton()">
                        <label class="form-check-label" for="confirm_check">
                            I confirm that I want to delete plot <strong><?php echo htmlspecialchars($plot['plot_number']); ?></strong>
                        </label>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <div>
                        <a href="view.php?id=<?php echo $plot_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                    <button type="submit" 
                            name="confirm_delete" 
                            id="deleteButton"
                            class="btn btn-delete" 
                            disabled>
                        <i class="fas fa-trash me-2"></i>Delete Plot Permanently
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
function toggleDeleteButton() {
    const confirmCheck = document.getElementById('confirm_check');
    const forceDeleteCheck = document.getElementById('force_delete');
    const deleteButton = document.getElementById('deleteButton');
    
    const hasDependencies = <?php echo $has_dependencies ? 'true' : 'false'; ?>;
    
    if (hasDependencies) {
        // If has dependencies, both checkboxes must be checked
        deleteButton.disabled = !(confirmCheck.checked && forceDeleteCheck.checked);
    } else {
        // If no dependencies, only confirm checkbox needs to be checked
        deleteButton.disabled = !confirmCheck.checked;
    }
}

function confirmDeletion() {
    const plotNumber = '<?php echo addslashes($plot['plot_number']); ?>';
    const hasDependencies = <?php echo $has_dependencies ? 'true' : 'false'; ?>;
    
    let message = 'Are you absolutely sure you want to delete plot "' + plotNumber + '"?\n\n';
    message += 'This action cannot be undone!';
    
    if (hasDependencies) {
        message += '\n\nThis will also permanently delete:\n';
        <?php foreach ($dependency_details as $dependency): ?>
        message += '- <?php echo $dependency['count']; ?> <?php echo addslashes($dependency['type']); ?>\n';
        <?php endforeach; ?>
    }
    
    return confirm(message);
}

// Disable delete button on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleDeleteButton();
});
</script>

<?php 
require_once '../../includes/footer.php';
?>