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
$user_id = $_SESSION['user_id'];

$errors = [];
$success = '';

// Get reservation ID
$reservation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$reservation_id) {
    $_SESSION['error'] = "Invalid reservation ID";
    header("Location: index.php");
    exit;
}

// Fetch reservation details
try {
    $sql = "SELECT r.*,
                   c.full_name as customer_name,
                   c.customer_id,
                   p.plot_id,
                   p.plot_number,
                   p.block_number,
                   p.area,
                   p.price_per_sqm,
                   p.selling_price,
                   p.project_id,
                   pr.project_name
            FROM reservations r
            LEFT JOIN customers c ON r.customer_id = c.customer_id
            LEFT JOIN plots p ON r.plot_id = p.plot_id
            LEFT JOIN projects pr ON p.project_id = pr.project_id
            WHERE r.reservation_id = ? AND r.company_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$reservation_id, $company_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        $_SESSION['error'] = "Reservation not found";
        header("Location: index.php");
        exit;
    }
    
    // Check if reservation can be edited
    if (in_array($reservation['status'], ['completed', 'cancelled'])) {
        $_SESSION['error'] = "Cannot edit a {$reservation['status']} reservation";
        header("Location: view.php?id=" . $reservation_id);
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching reservation: " . $e->getMessage());
    $_SESSION['error'] = "Database error";
    header("Location: index.php");
    exit;
}

// AJAX: Get plots by project (excluding current plot)
if (isset($_GET['action']) && $_GET['action'] === 'get_plots' && isset($_GET['project_id'])) {
    $project_id = intval($_GET['project_id']);
    
    try {
        $plots_sql = "SELECT plot_id, plot_number, block_number, area, 
                             price_per_sqm, selling_price, discount_amount,
                             (selling_price - COALESCE(discount_amount, 0)) as final_price,
                             status
                      FROM plots
                      WHERE project_id = ? AND company_id = ? 
                      AND (status = 'available' OR plot_id = ?)
                      AND NOT EXISTS (
                          SELECT 1 FROM reservations r 
                          WHERE r.plot_id = plots.plot_id 
                          AND r.company_id = plots.company_id
                          AND r.reservation_id != ?
                          AND r.status IN ('active', 'pending_approval', 'completed')
                      )
                      ORDER BY CAST(plot_number AS UNSIGNED), block_number";
        
        $plots_stmt = $conn->prepare($plots_sql);
        $plots_stmt->execute([$project_id, $company_id, $reservation['plot_id'], $reservation_id]);
        $plots = $plots_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'plots' => $plots]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// AJAX: Get plot details
if (isset($_GET['action']) && $_GET['action'] === 'get_plot_details' && isset($_GET['plot_id'])) {
    $plot_id = intval($_GET['plot_id']);
    
    try {
        $plot_sql = "SELECT p.*, pr.project_name 
                     FROM plots p
                     JOIN projects pr ON p.project_id = pr.project_id
                     WHERE p.plot_id = ? AND p.company_id = ?";
        $plot_stmt = $conn->prepare($plot_sql);
        $plot_stmt->execute([$plot_id, $company_id]);
        $plot = $plot_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($plot) {
            // Check if it's the current reservation's plot or available
            if ($plot_id != $reservation['plot_id']) {
                if ($plot['status'] !== 'available') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false, 
                        'error' => 'This plot is currently ' . $plot['status'] . ' and cannot be selected.',
                        'status' => $plot['status']
                    ]);
                    exit;
                }
                
                $check_reservation = $conn->prepare("
                    SELECT COUNT(*) FROM reservations 
                    WHERE plot_id = ? AND company_id = ? 
                    AND reservation_id != ?
                    AND status IN ('active', 'pending_approval', 'completed')
                ");
                $check_reservation->execute([$plot_id, $company_id, $reservation_id]);
                $has_reservation = $check_reservation->fetchColumn();
                
                if ($has_reservation > 0) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false, 
                        'error' => 'This plot already has an active reservation.',
                        'status' => 'reserved'
                    ]);
                    exit;
                }
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'plot' => $plot]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Fetch customers
try {
    $customers_sql = "SELECT customer_id, full_name, phone, email 
                      FROM customers 
                      WHERE company_id = ? AND is_active = 1 
                      ORDER BY full_name";
    $customers_stmt = $conn->prepare($customers_sql);
    $customers_stmt->execute([$company_id]);
    $customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Fetch projects
try {
    $projects_sql = "SELECT project_id, project_name, 
                            COALESCE(physical_location, '') as location,
                            available_plots
                     FROM projects 
                     WHERE company_id = ? AND is_active = 1 
                     ORDER BY project_name";
    $projects_stmt = $conn->prepare($projects_sql);
    $projects_stmt->execute([$company_id]);
    $projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $projects = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Basic validation
    if (empty($_POST['customer_id'])) $errors[] = "Customer is required";
    if (empty($_POST['plot_id'])) $errors[] = "Plot is required";
    if (empty($_POST['reservation_date'])) $errors[] = "Reservation date is required";
    if (empty($_POST['total_amount'])) $errors[] = "Total amount is required";
    if (empty($_POST['down_payment'])) $errors[] = "Down payment is required";

    // Plot availability check (if plot changed)
    if (!empty($_POST['plot_id']) && $_POST['plot_id'] != $reservation['plot_id']) {
        $check_plot_sql = "SELECT status FROM plots WHERE plot_id = ? AND company_id = ?";
        $check_plot_stmt = $conn->prepare($check_plot_sql);
        $check_plot_stmt->execute([$_POST['plot_id'], $company_id]);
        $plot_status = $check_plot_stmt->fetchColumn();
        
        if ($plot_status !== 'available') {
            $errors[] = "Selected plot is not available. Current status: " . ucfirst($plot_status);
        }
        
        $check_reservation_sql = "SELECT COUNT(*) FROM reservations 
                                  WHERE plot_id = ? AND company_id = ? 
                                  AND reservation_id != ?
                                  AND status IN ('active', 'pending_approval', 'completed')";
        $check_reservation_stmt = $conn->prepare($check_reservation_sql);
        $check_reservation_stmt->execute([$_POST['plot_id'], $company_id, $reservation_id]);
        $has_reservation = $check_reservation_stmt->fetchColumn();
        
        if ($has_reservation > 0) {
            $errors[] = "This plot already has an active reservation. Please select another plot.";
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Calculate amounts
            $payment_periods = intval($_POST['payment_periods'] ?? 20);
            $total_amount = floatval($_POST['total_amount']);
            $down_payment = floatval($_POST['down_payment']);
            $remaining_balance = $total_amount - $down_payment;
            $installment_amount = $payment_periods > 0 ? ($remaining_balance / $payment_periods) : 0;

            // Update reservation
            $sql = "UPDATE reservations SET
                        customer_id = ?,
                        plot_id = ?,
                        reservation_date = ?,
                        total_amount = ?,
                        down_payment = ?,
                        payment_periods = ?,
                        installment_amount = ?,
                        discount_percentage = ?,
                        discount_amount = ?,
                        title_holder_name = ?,
                        updated_at = NOW()
                    WHERE reservation_id = ? AND company_id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $_POST['customer_id'], 
                $_POST['plot_id'], 
                $_POST['reservation_date'],
                $total_amount, 
                $down_payment, 
                $payment_periods,
                $installment_amount, 
                $_POST['discount_percentage'] ?? 0,
                $_POST['discount_amount'] ?? 0, 
                $_POST['title_holder_name'] ?? null,
                $reservation_id,
                $company_id
            ]);

            // If plot changed, update old plot status back to available (if it was reserved for this reservation)
            if ($_POST['plot_id'] != $reservation['plot_id']) {
                // Check if old plot has this as the only reservation
                $check_old_plot = $conn->prepare("
                    SELECT COUNT(*) FROM reservations 
                    WHERE plot_id = ? AND company_id = ? 
                    AND reservation_id != ?
                    AND status IN ('active', 'pending_approval', 'completed')
                ");
                $check_old_plot->execute([$reservation['plot_id'], $company_id, $reservation_id]);
                $other_reservations = $check_old_plot->fetchColumn();
                
                if ($other_reservations == 0) {
                    $update_old_plot = $conn->prepare("UPDATE plots SET status = 'available' WHERE plot_id = ? AND company_id = ?");
                    $update_old_plot->execute([$reservation['plot_id'], $company_id]);
                }
            }

            $conn->commit();
            
            $_SESSION['success'] = "Reservation updated successfully!";
            header("Location: view.php?id=" . $reservation_id);
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
            error_log("DATABASE ERROR: " . $e->getMessage());
        }
    }
}

$page_title = 'Edit Reservation';
ob_start();
require_once '../../includes/header.php';
$header_content = ob_get_clean();

if (strpos($header_content, 'jquery') === false) {
    echo '<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>';
}
echo $header_content;

if (strpos($header_content, 'select2') === false) {
    echo '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />';
}
?>

<style>
.step-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    padding: 25px;
    margin-bottom: 20px;
    border-left: 4px solid #667eea;
}

.step-card .step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 50%;
    font-weight: bold;
    margin-right: 10px;
}

.step-card .step-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
}

.form-group label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 8px;
}

.form-control, .form-select {
    border-radius: 6px;
    border: 1px solid #dee2e6;
    padding: 10px 15px;
    font-size: 14px;
}

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.plot-info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 10px;
    margin-top: 20px;
}

.plot-info-card .info-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.plot-info-card .info-row:last-child {
    border-bottom: none;
}

.plot-info-card .info-label {
    font-size: 13px;
    opacity: 0.9;
}

.plot-info-card .info-value {
    font-size: 16px;
    font-weight: 600;
}

.calculation-summary {
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 20px;
    margin-top: 20px;
}

.calculation-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    font-size: 15px;
    border-bottom: 1px solid #dee2e6;
}

.calculation-row:last-child {
    border-bottom: none;
    font-size: 18px;
    font-weight: 700;
    color: #28a745;
    padding-top: 15px;
    margin-top: 10px;
    border-top: 2px solid #28a745;
}

.required {
    color: #dc3545;
    margin-left: 3px;
}

.btn-submit {
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    border: none;
    padding: 12px 40px;
    font-size: 16px;
    font-weight: 600;
    border-radius: 8px;
    color: white;
    transition: all 0.3s;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
}

.alert-info-edit {
    background: #d1ecf1;
    border: 2px solid #17a2b8;
    border-left: 5px solid #17a2b8;
    color: #0c5460;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.reservation-badge {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 14px;
}

.badge-draft { background: #6c757d; color: white; }
.badge-pending_approval { background: #ffc107; color: #000; }
.badge-active { background: #28a745; color: white; }
.badge-completed { background: #007bff; color: white; }
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-edit"></i> Edit Reservation</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Reservations</a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

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

        <div class="alert alert-info-edit">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-info-circle"></i>
                    <strong>Editing Reservation:</strong> <?php echo htmlspecialchars($reservation['reservation_number']); ?>
                    <span class="ms-3">|</span>
                    <span class="ms-2">Status:</span>
                    <span class="reservation-badge badge-<?php echo $reservation['status']; ?> ms-2">
                        <?php echo ucfirst(str_replace('_', ' ', $reservation['status'])); ?>
                    </span>
                </div>
                <div>
                    <a href="view.php?id=<?php echo $reservation_id; ?>" class="btn btn-sm btn-info">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                </div>
            </div>
        </div>

        <form method="POST" id="reservationForm">
            
            <!-- STEP 1: Customer & Plot Selection -->
            <div class="step-card">
                <div class="step-title">
                    <span class="step-number">1</span>
                    <span>Customer & Plot Selection</span>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Select Customer<span class="required">*</span></label>
                            <select name="customer_id" id="customer_id" class="form-control select2" required>
                                <option value="">-- Choose Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['customer_id']; ?>"
                                            <?php echo $customer['customer_id'] == $reservation['customer_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['full_name']); ?>
                                        <?php if ($customer['phone']): ?>
                                            (<?php echo htmlspecialchars($customer['phone']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Select Project<span class="required">*</span></label>
                            <select name="project_id" id="project_id" class="form-control select2" required>
                                <option value="">-- Choose Project --</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['project_id']; ?>"
                                            <?php echo $project['project_id'] == $reservation['project_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['project_name']); ?>
                                        (<?php echo $project['available_plots']; ?> plots available)
                                        <?php if ($project['location']): ?>
                                            - <?php echo htmlspecialchars($project['location']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Select Plot<span class="required">*</span></label>
                            <select name="plot_id" id="plot_id" class="form-control select2" required>
                                <option value="<?php echo $reservation['plot_id']; ?>" selected>
                                    Plot <?php echo htmlspecialchars($reservation['plot_number']); ?>
                                    <?php if ($reservation['block_number']): ?>
                                        (Block <?php echo htmlspecialchars($reservation['block_number']); ?>)
                                    <?php endif; ?>
                                    - <?php echo number_format($reservation['area'], 2); ?> m²
                                </option>
                            </select>
                            <small id="plotsLoading" style="display: none; color: #17a2b8;">
                                <i class="fas fa-spinner fa-spin"></i> Loading available plots...
                            </small>
                        </div>
                    </div>
                </div>

                <div id="plotInfoCard" class="plot-info-card" style="display: block;">
                    <h5 style="margin-bottom: 15px;"><i class="fas fa-map-marked-alt"></i> Selected Plot Information</h5>
                    <div class="info-row">
                        <span class="info-label">Plot Number:</span>
                        <span class="info-value" id="plotNumber"><?php echo htmlspecialchars($reservation['plot_number']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Block:</span>
                        <span class="info-value" id="blockNumber"><?php echo htmlspecialchars($reservation['block_number'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Area:</span>
                        <span class="info-value" id="plotArea"><?php echo number_format($reservation['area'], 2); ?> m²</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Price per m²:</span>
                        <span class="info-value" id="pricePerSqm">TZS <?php echo number_format($reservation['price_per_sqm'], 2); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Total Price:</span>
                        <span class="info-value" id="plotTotalPrice">TZS <?php echo number_format($reservation['selling_price'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- STEP 2: Reservation Details -->
            <div class="step-card">
                <div class="step-title">
                    <span class="step-number">2</span>
                    <span>Reservation Details</span>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Reservation Date<span class="required">*</span></label>
                            <input type="date" name="reservation_date" class="form-control" 
                                   value="<?php echo $reservation['reservation_date']; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Total Amount (TZS)<span class="required">*</span></label>
                            <input type="number" name="total_amount" id="total_amount" 
                                   class="form-control" step="0.01" 
                                   value="<?php echo $reservation['total_amount']; ?>" required>
                            <small class="form-text text-muted">You can adjust this amount if needed</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Discount (%)</label>
                            <input type="number" name="discount_percentage" id="discount_percentage" 
                                   class="form-control" step="0.01" 
                                   value="<?php echo $reservation['discount_percentage'] ?? 0; ?>" 
                                   min="0" max="100">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Discount Amount (TZS)</label>
                            <input type="number" name="discount_amount" id="discount_amount" 
                                   class="form-control" step="0.01" 
                                   value="<?php echo $reservation['discount_amount'] ?? 0; ?>" readonly>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Title Holder Name</label>
                            <input type="text" name="title_holder_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($reservation['title_holder_name'] ?? ''); ?>"
                                   placeholder="Leave blank if same as customer">
                        </div>
                    </div>
                </div>
            </div>

            <!-- STEP 3: Payment Terms -->
            <div class="step-card">
                <div class="step-title">
                    <span class="step-number">3</span>
                    <span>Payment Terms</span>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Down Payment (TZS)<span class="required">*</span></label>
                            <input type="number" name="down_payment" id="down_payment" 
                                   class="form-control" step="0.01" 
                                   value="<?php echo $reservation['down_payment']; ?>" min="0" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Payment Periods (Months)</label>
                            <input type="number" name="payment_periods" id="payment_periods" 
                                   class="form-control" 
                                   value="<?php echo $reservation['payment_periods'] ?? 20; ?>" min="1">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Monthly Installment (TZS)</label>
                            <input type="number" name="installment_amount" id="installment_amount" 
                                   class="form-control" step="0.01" 
                                   value="<?php echo $reservation['installment_amount']; ?>" readonly>
                        </div>
                    </div>
                </div>

                <div class="calculation-summary">
                    <h6 style="margin-bottom: 15px; font-weight: 600;">Payment Summary</h6>
                    <div class="calculation-row">
                        <span>Plot Price:</span>
                        <span id="displayBaseAmount">TZS <?php echo number_format($reservation['selling_price'], 2); ?></span>
                    </div>
                    <div class="calculation-row">
                        <span>Discount:</span>
                        <span id="displayDiscount">TZS <?php echo number_format($reservation['discount_amount'] ?? 0, 2); ?></span>
                    </div>
                    <div class="calculation-row">
                        <span>Total Amount:</span>
                        <span id="displayTotal">TZS <?php echo number_format($reservation['total_amount'], 2); ?></span>
                    </div>
                    <div class="calculation-row">
                        <span>Down Payment:</span>
                        <span id="displayDownPayment">TZS <?php echo number_format($reservation['down_payment'], 2); ?></span>
                    </div>
                    <div class="calculation-row">
                        <span>Remaining Balance:</span>
                        <span id="displayBalance">TZS <?php echo number_format($reservation['total_amount'] - $reservation['down_payment'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="row">
                <div class="col-12" style="margin-top: 20px;">
                    <a href="view.php?id=<?php echo $reservation_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-submit float-right">
                        <i class="fas fa-save"></i> Update Reservation
                    </button>
                </div>
            </div>

        </form>

    </div>
</section>

<script>
(function checkJQuery() {
    if (typeof jQuery === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://code.jquery.com/jquery-3.6.0.min.js';
        script.onload = initializePage;
        document.head.appendChild(script);
    } else {
        initializePage();
    }
})();

function initializePage() {
    if (typeof $.fn.select2 === 'undefined') {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
        document.head.appendChild(link);
        
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';
        script.onload = startApp;
        document.body.appendChild(script);
    } else {
        startApp();
    }
}

let basePlotPrice = <?php echo $reservation['selling_price']; ?>;

function startApp() {
    $(document).ready(function() {
        $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
        
        $('#project_id').on('change', loadPlots);
        $('#plot_id').on('change', loadPlotDetails);
        $('#discount_percentage, #down_payment, #payment_periods, #total_amount').on('input change', calculateAmounts);
        
        // Initial calculation
        calculateAmounts();
    });
}

function loadPlots() {
    const projectId = $('#project_id').val();
    const $plotSelect = $('#plot_id');
    const $loadingMsg = $('#plotsLoading');
    
    $plotSelect.prop('disabled', true).html('<option value="">-- Loading... --</option>');
    $loadingMsg.show();
    $('#plotInfoCard').hide();
    
    if (!projectId) {
        $plotSelect.html('<option value="">-- First Select a Project --</option>');
        $loadingMsg.hide();
        return;
    }
    
    $.ajax({
        url: 'edit.php?action=get_plots&project_id=' + projectId + '&id=<?php echo $reservation_id; ?>',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            $plotSelect.html('<option value="">-- Select Plot --</option>');
            
            if (response.success && response.plots && response.plots.length > 0) {
                $.each(response.plots, function(i, plot) {
                    const blockInfo = plot.block_number ? ` (Block ${plot.block_number})` : '';
                    const area = parseFloat(plot.area || 0).toFixed(2);
                    const price = parseFloat(plot.final_price || plot.selling_price || 0);
                    const optionText = `Plot ${plot.plot_number}${blockInfo} - ${area} m² - TZS ${formatNumber(price)}`;
                    
                    const option = $('<option>', {
                        value: plot.plot_id,
                        text: optionText
                    });
                    
                    if (plot.plot_id == <?php echo $reservation['plot_id']; ?>) {
                        option.prop('selected', true);
                    }
                    
                    $plotSelect.append(option);
                });
                $plotSelect.prop('disabled', false);
            } else {
                $plotSelect.html('<option value="">-- No Plots Available --</option>');
            }
            $loadingMsg.hide();
        },
        error: function(xhr, status, error) {
            console.error('Error loading plots:', error);
            $plotSelect.html('<option value="">-- Error Loading Plots --</option>');
            $loadingMsg.hide();
        }
    });
}

function loadPlotDetails() {
    const plotId = $('#plot_id').val();
    const $plotInfoCard = $('#plotInfoCard');
    
    if (!plotId) {
        $plotInfoCard.hide();
        return;
    }
    
    $.ajax({
        url: 'edit.php?action=get_plot_details&plot_id=' + plotId + '&id=<?php echo $reservation_id; ?>',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success && response.plot) {
                const plot = response.plot;
                
                if (plot.plot_id != <?php echo $reservation['plot_id']; ?> && plot.status !== 'available') {
                    alert('ERROR: This plot is ' + plot.status + ' and cannot be selected. Please select another plot.');
                    $('#plot_id').val('<?php echo $reservation['plot_id']; ?>').trigger('change');
                    return;
                }
                
                $('#plotNumber').text(plot.plot_number);
                $('#blockNumber').text(plot.block_number || 'N/A');
                $('#plotArea').text(formatNumber(plot.area) + ' m²');
                $('#pricePerSqm').text('TZS ' + formatNumber(plot.price_per_sqm));
                
                basePlotPrice = parseFloat(plot.final_price || plot.selling_price || 0);
                $('#plotTotalPrice').text('TZS ' + formatNumber(basePlotPrice));
                $('#total_amount').val(basePlotPrice.toFixed(2));
                
                $plotInfoCard.fadeIn();
                calculateAmounts();
            } else if (response.error) {
                alert('Plot Unavailable: ' + response.error);
                $('#plot_id').val('<?php echo $reservation['plot_id']; ?>').trigger('change');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading plot details:', error);
        }
    });
}

function calculateAmounts() {
    const totalAmount = parseFloat($('#total_amount').val()) || 0;
    const discountPercentage = parseFloat($('#discount_percentage').val()) || 0;
    const downPayment = parseFloat($('#down_payment').val()) || 0;
    const paymentPeriods = parseInt($('#payment_periods').val()) || 1;
    
    const discountAmount = (basePlotPrice * discountPercentage) / 100;
    $('#discount_amount').val(discountAmount.toFixed(2));
    
    const remainingBalance = totalAmount - downPayment;
    const installmentAmount = paymentPeriods > 0 ? (remainingBalance / paymentPeriods) : 0;
    $('#installment_amount').val(installmentAmount.toFixed(2));
    
    $('#displayBaseAmount').text('TZS ' + formatNumber(basePlotPrice));
    $('#displayDiscount').text('TZS ' + formatNumber(discountAmount));
    $('#displayTotal').text('TZS ' + formatNumber(totalAmount));
    $('#displayDownPayment').text('TZS ' + formatNumber(downPayment));
    $('#displayBalance').text('TZS ' + formatNumber(remainingBalance));
}

function formatNumber(num) {
    return parseFloat(num || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
</script>

<?php require_once '../../includes/footer.php'; ?>