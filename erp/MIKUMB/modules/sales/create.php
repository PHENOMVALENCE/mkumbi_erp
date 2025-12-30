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

$errors = [];
$success = '';

// AJAX: Get plots by project
if (isset($_GET['action']) && $_GET['action'] === 'get_plots' && isset($_GET['project_id'])) {
    $project_id = intval($_GET['project_id']);
    
    try {
        $plots_sql = "SELECT plot_id, plot_number, block_number, area, 
                             price_per_sqm, selling_price, discount_amount,
                             (selling_price - COALESCE(discount_amount, 0)) as final_price,
                             status
                      FROM plots
                      WHERE project_id = ? 
                      AND company_id = ? 
                      AND status = 'available'
                      ORDER BY CAST(plot_number AS UNSIGNED), block_number";
        
        $plots_stmt = $conn->prepare($plots_sql);
        $plots_stmt->execute([$project_id, $company_id]);
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
    
    // Validation
    if (empty($_POST['customer_id'])) $errors[] = "Customer is required";
    if (empty($_POST['plot_id'])) $errors[] = "Plot is required";
    if (empty($_POST['reservation_date'])) $errors[] = "Reservation date is required";
    if (empty($_POST['total_amount'])) $errors[] = "Total amount is required";
    if (empty($_POST['down_payment'])) $errors[] = "Down payment is required";

    // Check plot availability
    if (!empty($_POST['plot_id'])) {
        $check_plot_sql = "SELECT status FROM plots WHERE plot_id = ? AND company_id = ?";
        $check_plot_stmt = $conn->prepare($check_plot_sql);
        $check_plot_stmt->execute([$_POST['plot_id'], $company_id]);
        $plot_status = $check_plot_stmt->fetchColumn();
        
        if ($plot_status !== 'available') {
            $errors[] = "Selected plot is no longer available";
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Generate reservation number
            $year = date('Y');
            $count_sql = "SELECT COUNT(*) FROM reservations WHERE company_id = ? AND YEAR(reservation_date) = ?";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->execute([$company_id, $year]);
            $count = $count_stmt->fetchColumn() + 1;
            $reservation_number = 'RES-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

            // Calculate amounts
            $payment_periods = intval($_POST['payment_periods'] ?? 20);
            $total_amount = floatval($_POST['total_amount']);
            $down_payment = floatval($_POST['down_payment']);
            $remaining_balance = $total_amount - $down_payment;
            $installment_amount = $payment_periods > 0 ? ($remaining_balance / $payment_periods) : 0;

            // Insert reservation with 'draft' status (pending approval)
            $sql = "INSERT INTO reservations (
                company_id, customer_id, plot_id, reservation_date, reservation_number,
                total_amount, down_payment, payment_periods, installment_amount,
                discount_percentage, discount_amount, title_holder_name, 
                status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $company_id, 
                $_POST['customer_id'], 
                $_POST['plot_id'], 
                $_POST['reservation_date'],
                $reservation_number, 
                $total_amount, 
                $down_payment, 
                $payment_periods,
                $installment_amount, 
                $_POST['discount_percentage'] ?? 0,
                $_POST['discount_amount'] ?? 0, 
                $_POST['title_holder_name'] ?? null,
                $_SESSION['user_id']
            ]);

            $reservation_id = $conn->lastInsertId();

            // Create payment record with 'pending_approval' status
            if ($down_payment > 0) {
                $payment_year = date('Y', strtotime($_POST['payment_date']));
                $payment_count_sql = "SELECT COUNT(*) FROM payments 
                                     WHERE company_id = ? AND YEAR(payment_date) = ?";
                $payment_count_stmt = $conn->prepare($payment_count_sql);
                $payment_count_stmt->execute([$company_id, $payment_year]);
                $payment_count = $payment_count_stmt->fetchColumn() + 1;
                $payment_number = 'PAY-' . $payment_year . '-' . str_pad($payment_count, 4, '0', STR_PAD_LEFT);

                $payment_sql = "INSERT INTO payments (
                    company_id, reservation_id, payment_date, payment_number, amount,
                    payment_method, bank_name, transaction_reference, remarks, 
                    status, payment_type, submitted_by, submitted_at, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', 'down_payment', ?, NOW(), ?)";

                $payment_stmt = $conn->prepare($payment_sql);
                $payment_stmt->execute([
                    $company_id, 
                    $reservation_id, 
                    $_POST['payment_date'], 
                    $payment_number,
                    $down_payment, 
                    $_POST['payment_method'] ?? 'cash',
                    $_POST['bank_name'] ?? null, 
                    $_POST['transaction_reference'] ?? null,
                    'Down payment for reservation ' . $reservation_number,
                    $_SESSION['user_id'],
                    $_SESSION['user_id']
                ]);
            }

            // DO NOT update plot status yet - wait for approval
            // DO NOT update project counts yet - wait for approval

            $conn->commit();
            
            $_SESSION['success'] = "Reservation created successfully! Reservation Number: " . $reservation_number . ". Awaiting manager approval.";
            header("Location: index.php");
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = 'New Reservation';

// Check if jQuery is already loaded in header
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

.pending-badge {
    display: inline-block;
    background: #ffc107;
    color: #856404;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 10px;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1><i class="fas fa-plus-circle"></i> New Reservation</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Reservations</a></li>
                    <li class="breadcrumb-item active">New</li>
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

        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Note:</strong> All new reservations require manager approval before they are activated. 
            The reservation and down payment will be in <span class="pending-badge">PENDING APPROVAL</span> status until reviewed.
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
                                    <option value="<?php echo $customer['customer_id']; ?>">
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
                                    <option value="<?php echo $project['project_id']; ?>">
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
                            <select name="plot_id" id="plot_id" class="form-control select2" required disabled>
                                <option value="">-- First Select a Project --</option>
                            </select>
                            <small id="plotsLoading" style="display: none; color: #17a2b8;">
                                <i class="fas fa-spinner fa-spin"></i> Loading plots...
                            </small>
                        </div>
                    </div>
                </div>

                <div id="plotInfoCard" class="plot-info-card" style="display: none;">
                    <h5 style="margin-bottom: 15px;"><i class="fas fa-map-marked-alt"></i> Selected Plot Information</h5>
                    <div class="info-row">
                        <span class="info-label">Plot Number:</span>
                        <span class="info-value" id="plotNumber">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Block:</span>
                        <span class="info-value" id="blockNumber">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Area:</span>
                        <span class="info-value" id="plotArea">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Price per m²:</span>
                        <span class="info-value" id="pricePerSqm">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Total Price:</span>
                        <span class="info-value" id="plotTotalPrice">-</span>
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
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Total Amount (TZS)<span class="required">*</span></label>
                            <input type="number" name="total_amount" id="total_amount" 
                                   class="form-control" step="0.01" readonly required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Discount (%)</label>
                            <input type="number" name="discount_percentage" id="discount_percentage" 
                                   class="form-control" step="0.01" value="0" min="0" max="100">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Discount Amount (TZS)</label>
                            <input type="number" name="discount_amount" id="discount_amount" 
                                   class="form-control" step="0.01" value="0" readonly>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Title Holder Name</label>
                            <input type="text" name="title_holder_name" class="form-control" 
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
                                   class="form-control" step="0.01" value="0" min="0" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Payment Periods (Months)</label>
                            <input type="number" name="payment_periods" id="payment_periods" 
                                   class="form-control" value="20" min="1">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Monthly Installment (TZS)</label>
                            <input type="number" name="installment_amount" id="installment_amount" 
                                   class="form-control" step="0.01" readonly>
                        </div>
                    </div>
                </div>

                <div class="calculation-summary">
                    <h6 style="margin-bottom: 15px; font-weight: 600;">Payment Summary</h6>
                    <div class="calculation-row">
                        <span>Plot Price:</span>
                        <span id="displayBaseAmount">TZS 0.00</span>
                    </div>
                    <div class="calculation-row">
                        <span>Discount:</span>
                        <span id="displayDiscount">TZS 0.00</span>
                    </div>
                    <div class="calculation-row">
                        <span>Total Amount:</span>
                        <span id="displayTotal">TZS 0.00</span>
                    </div>
                    <div class="calculation-row">
                        <span>Down Payment:</span>
                        <span id="displayDownPayment">TZS 0.00</span>
                    </div>
                    <div class="calculation-row">
                        <span>Remaining Balance:</span>
                        <span id="displayBalance">TZS 0.00</span>
                    </div>
                </div>
            </div>

            <!-- STEP 4: Down Payment Details -->
            <div class="step-card">
                <div class="step-title">
                    <span class="step-number">4</span>
                    <span>Down Payment Details</span>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Payment Date<span class="required">*</span></label>
                            <input type="date" name="payment_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Payment Method<span class="required">*</span></label>
                            <select name="payment_method" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" 
                                   placeholder="e.g., CRDB Bank">
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Transaction Reference</label>
                            <input type="text" name="transaction_reference" class="form-control" 
                                   placeholder="e.g., TRX123456789">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="row">
                <div class="col-12" style="margin-top: 20px;">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-submit float-right">
                        <i class="fas fa-check"></i> Submit for Approval
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

let basePlotPrice = 0;

function startApp() {
    $(document).ready(function() {
        $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
        
        $('#project_id').on('change', loadPlots);
        $('#plot_id').on('change', loadPlotDetails);
        $('#discount_percentage, #down_payment, #payment_periods').on('input change', calculateAmounts);
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
        url: 'create.php?action=get_plots&project_id=' + projectId,
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            $plotSelect.html('<option value="">-- Select Plot --</option>');
            
            if (response.success && response.plots && response.plots.length > 0) {
                $.each(response.plots, function(i, plot) {
                    const blockInfo = plot.block_number ? ` (${plot.block_number})` : '';
                    const area = parseFloat(plot.area || 0).toFixed(2);
                    const price = parseFloat(plot.final_price || plot.selling_price || 0);
                    const optionText = `Plot ${plot.plot_number}${blockInfo} - ${area} m² - TZS ${formatNumber(price)}`;
                    
                    $plotSelect.append($('<option>', {
                        value: plot.plot_id,
                        text: optionText
                    }));
                });
                $plotSelect.prop('disabled', false);
            } else {
                $plotSelect.html('<option value="">-- No Available Plots --</option>');
            }
            $loadingMsg.hide();
        },
        error: function() {
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
        $('#total_amount').val('');
        basePlotPrice = 0;
        calculateAmounts();
        return;
    }
    
    $.ajax({
        url: 'create.php?action=get_plot_details&plot_id=' + plotId,
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success && response.plot) {
                const plot = response.plot;
                
                $('#plotNumber').text(plot.plot_number);
                $('#blockNumber').text(plot.block_number || 'N/A');
                $('#plotArea').text(formatNumber(plot.area) + ' m²');
                $('#pricePerSqm').text('TZS ' + formatNumber(plot.price_per_sqm));
                
                basePlotPrice = parseFloat(plot.final_price || plot.selling_price || 0);
                $('#plotTotalPrice').text('TZS ' + formatNumber(basePlotPrice));
                $('#total_amount').val(basePlotPrice.toFixed(2));
                
                $plotInfoCard.fadeIn();
                calculateAmounts();
            }
        }
    });
}

function calculateAmounts() {
    const discountPercentage = parseFloat($('#discount_percentage').val()) || 0;
    const downPayment = parseFloat($('#down_payment').val()) || 0;
    const paymentPeriods = parseInt($('#payment_periods').val()) || 1;
    
    const discountAmount = (basePlotPrice * discountPercentage) / 100;
    $('#discount_amount').val(discountAmount.toFixed(2));
    
    const totalAmount = basePlotPrice - discountAmount;
    $('#total_amount').val(totalAmount.toFixed(2));
    
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