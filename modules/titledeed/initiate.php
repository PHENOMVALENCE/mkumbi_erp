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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $customer_id = $_POST['customer_id'] ?? null;
        $reservation_id = $_POST['reservation_id'] ?? null;
        $plot_id = $_POST['plot_id'] ?? null;
        $total_cost = $_POST['total_cost'] ?? 0;
        $customer_contribution = $_POST['customer_contribution'] ?? 0;
        $expected_completion_date = $_POST['expected_completion_date'] ?? null;
        $assigned_to = $_POST['assigned_to'] ?? null;
        $notes = $_POST['notes'] ?? '';

        // Validation
        if (!$customer_id || !$plot_id) {
            throw new Exception("Customer and Plot are required");
        }

        if ($total_cost <= 0) {
            throw new Exception("Total cost must be greater than zero");
        }

        // Generate processing number
        $year = date('Y');
        $count_sql = "SELECT COUNT(*) as count FROM title_deed_processing 
                     WHERE company_id = ? AND YEAR(started_date) = ?";
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->execute([$company_id, $year]);
        $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $processing_number = 'TD-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

        // Insert title deed processing
        $insert_sql = "INSERT INTO title_deed_processing (
            company_id, processing_number, customer_id, plot_id, reservation_id,
            current_stage, total_cost, customer_contribution,
            started_date, expected_completion_date,
            assigned_to, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, 'startup', ?, ?, CURDATE(), ?, ?, ?, ?, NOW())";
        
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->execute([
            $company_id, $processing_number, $customer_id, $plot_id, $reservation_id,
            $total_cost, $customer_contribution, $expected_completion_date,
            $assigned_to, $notes, $user_id
        ]);

        $processing_id = $conn->lastInsertId();

        // Insert first stage record
        $stage_sql = "INSERT INTO title_deed_stages (
            company_id, processing_id, stage_name, stage_order, stage_status,
            started_date, created_by, created_at
        ) VALUES (?, ?, 'startup', 1, 'in_progress', CURDATE(), ?, NOW())";
        
        $stage_stmt = $conn->prepare($stage_sql);
        $stage_stmt->execute([$company_id, $processing_id, $user_id]);

        $success = "Title deed processing initiated successfully! Processing #: " . $processing_number;
        
        // Redirect after 2 seconds
        header("refresh:2;url=view.php?id=" . $processing_id);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch customers with active reservations
try {
    $customers_sql = "SELECT 
        c.customer_id,
        c.full_name,
        COALESCE(c.phone, c.phone1) AS phone,
        c.email,
        COUNT(DISTINCT r.reservation_id) as reservation_count
    FROM customers c
    INNER JOIN reservations r ON c.customer_id = r.customer_id
    WHERE c.company_id = ? 
    AND c.is_active = 1
    AND r.company_id = ?
    AND r.is_active = 1
    GROUP BY c.customer_id, c.full_name, c.phone, c.phone1, c.email
    HAVING reservation_count > 0
    ORDER BY c.full_name";
    
    $customers_stmt = $conn->prepare($customers_sql);
    $customers_stmt->execute([$company_id, $company_id]);
    $customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching customers: " . $e->getMessage());
    $customers = [];
    $error = "Error loading customers: " . $e->getMessage();
}

// Fetch staff members for assignment
try {
    $staff_sql = "SELECT user_id, full_name 
                 FROM users 
                 WHERE company_id = ? AND is_active = 1
                 ORDER BY full_name";
    $staff_stmt = $conn->prepare($staff_sql);
    $staff_stmt->execute([$company_id]);
    $staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching staff: " . $e->getMessage());
    $staff = [];
}

$page_title = 'Initiate Title Deed Processing';
require_once '../../includes/header.php';
?>

<style>
.form-card {
    background: #fff;
    border-radius: 8px;
    padding: 2rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}

.form-section {
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid #e9ecef;
}

.form-section:last-child {
    border-bottom: none;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #007bff;
    display: inline-block;
}

.info-box {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
}

.cost-summary {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 1rem;
    margin-top: 1rem;
}

.cost-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #dee2e6;
}

.cost-row:last-child {
    border-bottom: none;
    font-weight: 600;
    font-size: 1.1rem;
    color: #2c3e50;
}

.customer-details {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 6px;
    margin-top: 1rem;
    display: none;
}

.plot-details {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 6px;
    margin-top: 1rem;
    display: none;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size: 1.5rem;">
                    <i class="fas fa-plus-circle me-2"></i>Initiate Title Deed Processing
                </h1>
            </div>
            <div class="col-sm-6 text-end">
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Processing
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <div class="mt-2">
                <small><i class="fas fa-info-circle me-1"></i>Redirecting to processing details...</small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($customers)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>No Eligible Customers Found</strong><br>
            There are currently no customers with active reservations.
            <div class="mt-3">
                <a href="../reservations/index.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-file-contract me-1"></i>View Reservations
                </a>
                <a href="../customers/index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-users me-1"></i>View Customers
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <div class="form-card">
                <form method="POST" id="initiateForm">
                    <!-- Customer Selection -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-user me-2"></i>Customer Information
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <label class="form-label">Select Customer <span class="text-danger">*</span></label>
                                <select name="customer_id" id="customerSelect" class="form-select" required 
                                        <?= empty($customers) ? 'disabled' : '' ?>>
                                    <option value="">-- Select Customer --</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= $customer['customer_id'] ?>"
                                                data-phone="<?= htmlspecialchars($customer['phone'] ?? '') ?>"
                                                data-email="<?= htmlspecialchars($customer['email'] ?? '') ?>">
                                            <?= htmlspecialchars($customer['full_name']) ?> 
                                            (<?= $customer['reservation_count'] ?> reservation<?= $customer['reservation_count'] > 1 ? 's' : '' ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    <?= count($customers) ?> customer(s) with active reservations found
                                </small>
                            </div>
                        </div>

                        <div id="customerDetails" class="customer-details">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Phone:</strong>
                                    <div id="customerPhone" class="text-muted"></div>
                                </div>
                                <div class="col-md-6">
                                    <strong>Email:</strong>
                                    <div id="customerEmail" class="text-muted"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Plot Selection -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-map-marker-alt me-2"></i>Plot & Reservation
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <label class="form-label">Select Reservation/Plot <span class="text-danger">*</span></label>
                                <select name="reservation_id" id="reservationSelect" class="form-select" required disabled>
                                    <option value="">-- Select Customer First --</option>
                                </select>
                                <input type="hidden" name="plot_id" id="plotId">
                                <small class="text-muted">Select a customer first to see their reservations</small>
                            </div>
                        </div>

                        <div id="plotDetails" class="plot-details">
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>Plot Number:</strong>
                                    <div id="plotNumber" class="text-muted"></div>
                                </div>
                                <div class="col-md-4">
                                    <strong>Project:</strong>
                                    <div id="projectName" class="text-muted"></div>
                                </div>
                                <div class="col-md-4">
                                    <strong>Plot Size:</strong>
                                    <div id="plotSize" class="text-muted"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cost Information -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-dollar-sign me-2"></i>Cost Information
                        </div>
                        
                        <div class="info-box">
                            <i class="fas fa-info-circle"></i>
                            <strong>Note:</strong> Enter the total processing cost and customer contribution amount
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Total Processing Cost (TZS) <span class="text-danger">*</span></label>
                                <input type="number" name="total_cost" id="totalCost" class="form-control" 
                                       min="0" step="1000" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Customer Contribution (TZS) <span class="text-danger">*</span></label>
                                <input type="number" name="customer_contribution" id="customerContribution" 
                                       class="form-control" min="0" step="1000" required>
                            </div>
                        </div>

                        <div class="cost-summary">
                            <div class="cost-row">
                                <span>Total Cost:</span>
                                <span id="summaryTotal">TZS 0</span>
                            </div>
                            <div class="cost-row">
                                <span>Customer Pays:</span>
                                <span id="summaryCustomer">TZS 0</span>
                            </div>
                            <div class="cost-row">
                                <span>Company Absorbs:</span>
                                <span id="summaryCompany" class="text-primary">TZS 0</span>
                            </div>
                        </div>
                    </div>

                    <!-- Processing Details -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-tasks me-2"></i>Processing Details
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Expected Completion Date</label>
                                <input type="date" name="expected_completion_date" class="form-control"
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Assign To</label>
                                <select name="assigned_to" class="form-select">
                                    <option value="">-- Assign Later --</option>
                                    <?php foreach ($staff as $member): ?>
                                        <option value="<?= $member['user_id'] ?>">
                                            <?= htmlspecialchars($member['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-12">
                                <label class="form-label">Notes / Special Instructions</label>
                                <textarea name="notes" class="form-control" rows="3" 
                                          placeholder="Enter any special notes or instructions..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary" <?= empty($customers) ? 'disabled' : '' ?>>
                            <i class="fas fa-check me-1"></i>Initiate Processing
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-info-circle me-2"></i>Processing Stages
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item">
                            <strong>1. Startup</strong>
                            <p class="mb-0 small text-muted">Initial documentation and application preparation</p>
                        </div>
                        <div class="list-group-item">
                            <strong>2. Municipal</strong>
                            <p class="mb-0 small text-muted">Municipal council review and approval</p>
                        </div>
                        <div class="list-group-item">
                            <strong>3. Ministry of Land</strong>
                            <p class="mb-0 small text-muted">National ministry processing and verification</p>
                        </div>
                        <div class="list-group-item">
                            <strong>4. Approved</strong>
                            <p class="mb-0 small text-muted">Title deed approved and ready for printing</p>
                        </div>
                        <div class="list-group-item">
                            <strong>5. Received</strong>
                            <p class="mb-0 small text-muted">Physical title deed received from authorities</p>
                        </div>
                        <div class="list-group-item">
                            <strong>6. Delivered</strong>
                            <p class="mb-0 small text-muted">Title deed handed over to customer</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('customerSelect').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const customerId = this.value;
    
    if (customerId) {
        document.getElementById('customerPhone').textContent = selectedOption.dataset.phone || 'N/A';
        document.getElementById('customerEmail').textContent = selectedOption.dataset.email || 'N/A';
        document.getElementById('customerDetails').style.display = 'block';
        
        loadReservations(customerId);
    } else {
        document.getElementById('customerDetails').style.display = 'none';
        document.getElementById('reservationSelect').disabled = true;
        document.getElementById('reservationSelect').innerHTML = '<option value="">-- Select Customer First --</option>';
        document.getElementById('plotDetails').style.display = 'none';
    }
});

function loadReservations(customerId) {
    const select = document.getElementById('reservationSelect');
    select.innerHTML = '<option value="">Loading...</option>';
    select.disabled = true;
    
    fetch(`get_customer_reservations.php?customer_id=${customerId}`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">-- Select Reservation --</option>';
            
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            
            if (data.length === 0) {
                select.innerHTML = '<option value="">No reservations found</option>';
                return;
            }
            
            data.forEach(reservation => {
                const option = document.createElement('option');
                option.value = reservation.reservation_id;
                option.textContent = `${reservation.plot_number} - ${reservation.project_name}`;
                option.dataset.plotId = reservation.plot_id;
                option.dataset.plotNumber = reservation.plot_number;
                option.dataset.projectName = reservation.project_name;
                option.dataset.plotSize = reservation.plot_size;
                select.appendChild(option);
            });
            
            select.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            select.innerHTML = '<option value="">Error loading reservations</option>';
        });
}

document.getElementById('reservationSelect').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    
    if (this.value) {
        document.getElementById('plotId').value = selectedOption.dataset.plotId;
        document.getElementById('plotNumber').textContent = selectedOption.dataset.plotNumber;
        document.getElementById('projectName').textContent = selectedOption.dataset.projectName;
        document.getElementById('plotSize').textContent = selectedOption.dataset.plotSize + ' sqm';
        document.getElementById('plotDetails').style.display = 'block';
    } else {
        document.getElementById('plotDetails').style.display = 'none';
    }
});

function updateCostSummary() {
    const totalCost = parseFloat(document.getElementById('totalCost').value) || 0;
    const customerContribution = parseFloat(document.getElementById('customerContribution').value) || 0;
    const companyAbsorbs = totalCost - customerContribution;
    
    document.getElementById('summaryTotal').textContent = 'TZS ' + totalCost.toLocaleString();
    document.getElementById('summaryCustomer').textContent = 'TZS ' + customerContribution.toLocaleString();
    document.getElementById('summaryCompany').textContent = 'TZS ' + companyAbsorbs.toLocaleString();
}

document.getElementById('totalCost').addEventListener('input', updateCostSummary);
document.getElementById('customerContribution').addEventListener('input', updateCostSummary);

document.getElementById('initiateForm').addEventListener('submit', function(e) {
    const totalCost = parseFloat(document.getElementById('totalCost').value) || 0;
    const customerContribution = parseFloat(document.getElementById('customerContribution').value) || 0;
    
    if (customerContribution > totalCost) {
        e.preventDefault();
        alert('Customer contribution cannot exceed total cost');
        return false;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>