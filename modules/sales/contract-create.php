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

// Fetch active reservations that don't have contracts yet
try {
    $stmt = $conn->prepare("
        SELECT r.*, 
               c.full_name as customer_name,
               COALESCE(c.phone, c.phone1) as customer_phone,
               COALESCE(c.national_id, c.tin_number, c.id_number) as customer_id_number,
               p.plot_number, p.block_number, p.area,
               pr.project_name
        FROM reservations r
        INNER JOIN customers c ON r.customer_id = c.customer_id
        INNER JOIN plots p ON r.plot_id = p.plot_id
        INNER JOIN projects pr ON p.project_id = pr.project_id
        LEFT JOIN plot_contracts pc ON r.reservation_id = pc.reservation_id
        WHERE r.company_id = ? 
        AND r.is_active = 1 
        AND r.status = 'active'
        AND pc.contract_id IS NULL
        ORDER BY r.reservation_date DESC
    ");
    $stmt->execute([$company_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching reservations: " . $e->getMessage());
    $reservations = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation
    if (empty($_POST['reservation_id'])) {
        $errors[] = "Reservation is required";
    }
    if (empty($_POST['contract_number'])) {
        $errors[] = "Contract number is required";
    }
    if (empty($_POST['contract_date'])) {
        $errors[] = "Contract date is required";
    }
    if (empty($_POST['contract_type'])) {
        $errors[] = "Contract type is required";
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Handle file uploads
            $contract_template_path = null;
            $signed_contract_path = null;

            $upload_dir = '../../uploads/contracts/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Upload contract template
            if (!empty($_FILES['contract_template']['name'])) {
                $file_ext = strtolower(pathinfo($_FILES['contract_template']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['pdf', 'doc', 'docx'];
                
                if (in_array($file_ext, $allowed_extensions)) {
                    $file_name = 'contract_template_' . time() . '_' . uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['contract_template']['tmp_name'], $file_path)) {
                        $contract_template_path = 'uploads/contracts/' . $file_name;
                    }
                } else {
                    $errors[] = "Contract template must be PDF, DOC, or DOCX";
                }
            }

            // Upload signed contract
            if (!empty($_FILES['signed_contract']['name'])) {
                $file_ext = strtolower(pathinfo($_FILES['signed_contract']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['pdf'];
                
                if (in_array($file_ext, $allowed_extensions)) {
                    $file_name = 'signed_contract_' . time() . '_' . uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['signed_contract']['tmp_name'], $file_path)) {
                        $signed_contract_path = 'uploads/contracts/' . $file_name;
                    }
                } else {
                    $errors[] = "Signed contract must be PDF";
                }
            }

            if (empty($errors)) {
                // Get seller info from company
                $stmt = $conn->prepare("SELECT company_name, tax_identification_number FROM companies WHERE company_id = ?");
                $stmt->execute([$company_id]);
                $company = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get reservation details
                $stmt = $conn->prepare("
                    SELECT r.*, c.full_name, COALESCE(c.national_id, c.tin_number, c.id_number) as buyer_id
                    FROM reservations r
                    INNER JOIN customers c ON r.customer_id = c.customer_id
                    WHERE r.reservation_id = ?
                ");
                $stmt->execute([$_POST['reservation_id']]);
                $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

                // Insert contract
                $sql = "INSERT INTO plot_contracts (
                    company_id, reservation_id, contract_number, contract_date,
                    contract_type, contract_duration_months, contract_terms,
                    special_conditions, seller_name, seller_id_number,
                    buyer_name, buyer_id_number,
                    witness1_name, witness1_id_number,
                    witness2_name, witness2_id_number,
                    lawyer_name, notary_name, notary_stamp_number,
                    contract_template_path, signed_contract_path,
                    status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $company_id,
                    $_POST['reservation_id'],
                    $_POST['contract_number'],
                    $_POST['contract_date'],
                    $_POST['contract_type'],
                    !empty($_POST['contract_duration_months']) ? $_POST['contract_duration_months'] : null,
                    $_POST['contract_terms'] ?? null,
                    $_POST['special_conditions'] ?? null,
                    $company['company_name'] ?? 'Company',
                    $company['tax_identification_number'] ?? null,
                    $reservation['full_name'],
                    $reservation['buyer_id'],
                    $_POST['witness1_name'] ?? null,
                    $_POST['witness1_id_number'] ?? null,
                    $_POST['witness2_name'] ?? null,
                    $_POST['witness2_id_number'] ?? null,
                    $_POST['lawyer_name'] ?? null,
                    $_POST['notary_name'] ?? null,
                    $_POST['notary_stamp_number'] ?? null,
                    $contract_template_path,
                    $signed_contract_path,
                    !empty($signed_contract_path) ? 'signed' : 'draft',
                    $_SESSION['user_id']
                ]);

                $conn->commit();
                $success = "Contract created successfully!";
                header("refresh:2;url=contracts.php");
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error creating contract: " . $e->getMessage());
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = 'Create Plot Contract';
require_once '../../includes/header.php';
?>

<style>
.form-section {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
}

.form-section-header {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
}

.form-section-header i {
    margin-right: 0.5rem;
    color: #007bff;
}

.required-field::after {
    content: " *";
    color: #dc3545;
}

.btn-save {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    border: none;
    padding: 0.75rem 2rem;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(17, 153, 142, 0.3);
    color: white;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(17, 153, 142, 0.4);
    color: white;
}

.info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 10px;
    margin-bottom: 1.5rem;
}

.info-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}

.info-item:last-child {
    border-bottom: none;
}

.file-upload-label {
    cursor: pointer;
    display: block;
    padding: 1rem;
    border: 2px dashed #cbd5e0;
    border-radius: 8px;
    text-align: center;
    transition: all 0.3s;
}

.file-upload-label:hover {
    border-color: #007bff;
    background: #f8f9fa;
}

.file-upload-label i {
    font-size: 2rem;
    color: #007bff;
    margin-bottom: 0.5rem;
}

.selected-file {
    margin-top: 0.5rem;
    padding: 0.5rem;
    background: #e7f3ff;
    border-radius: 4px;
    font-size: 0.9rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-contract text-primary me-2"></i>Create Contract
                </h1>
                <p class="text-muted small mb-0 mt-1">Generate a new plot sale/lease contract</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="contracts.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Contracts
                    </a>
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
            <p class="mb-0 mt-2"><i class="fas fa-spinner fa-spin me-2"></i>Redirecting...</p>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (empty($reservations)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>No Active Reservations Available</strong>
            <p class="mb-0 mt-2">There are no active reservations without contracts. Please create a reservation first.</p>
            <a href="create.php" class="btn btn-primary btn-sm mt-3">
                <i class="fas fa-plus me-1"></i> Create Reservation
            </a>
        </div>
        <?php else: ?>

        <form method="POST" enctype="multipart/form-data" id="contractForm">
            <div class="row">
                <div class="col-lg-8">
                    
                    <!-- Section 1: Contract Details -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class="fas fa-info-circle"></i>
                            <span>Section 1: Contract Information</span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required-field">Reservation</label>
                                <select name="reservation_id" id="reservation_id" class="form-select" required onchange="loadReservationDetails()">
                                    <option value="">Select Reservation</option>
                                    <?php foreach ($reservations as $reservation): ?>
                                        <option value="<?php echo $reservation['reservation_id']; ?>"
                                                data-customer="<?php echo htmlspecialchars($reservation['customer_name']); ?>"
                                                data-phone="<?php echo htmlspecialchars($reservation['customer_phone']); ?>"
                                                data-id="<?php echo htmlspecialchars($reservation['customer_id_number']); ?>"
                                                data-plot="Plot <?php echo htmlspecialchars($reservation['plot_number']); ?> - <?php echo htmlspecialchars($reservation['project_name']); ?>"
                                                data-amount="<?php echo number_format($reservation['total_amount'], 2); ?>"
                                                data-reservation-number="<?php echo htmlspecialchars($reservation['reservation_number']); ?>">
                                            <?php echo htmlspecialchars($reservation['reservation_number']); ?> - 
                                            <?php echo htmlspecialchars($reservation['customer_name']); ?> - 
                                            Plot <?php echo htmlspecialchars($reservation['plot_number']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label required-field">Contract Number</label>
                                <input type="text" 
                                       name="contract_number" 
                                       class="form-control" 
                                       value="CNT-<?php echo date('Y'); ?>-<?php echo str_pad($company_id, 4, '0', STR_PAD_LEFT) . rand(100, 999); ?>"
                                       required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label required-field">Contract Date</label>
                                <input type="date" 
                                       name="contract_date" 
                                       class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>"
                                       required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label required-field">Contract Type</label>
                                <select name="contract_type" id="contract_type" class="form-select" required onchange="toggleDuration()">
                                    <option value="">Select Type</option>
                                    <option value="sale">Outright Sale</option>
                                    <option value="installment">Installment Sale</option>
                                    <option value="lease">Lease Agreement</option>
                                </select>
                            </div>

                            <div class="col-md-12" id="duration_field" style="display: none;">
                                <label class="form-label">Contract Duration (Months)</label>
                                <input type="number" 
                                       name="contract_duration_months" 
                                       class="form-control" 
                                       min="1"
                                       placeholder="e.g., 12, 24, 36">
                                <small class="text-muted">Required for installment and lease contracts</small>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Contract Terms & Conditions</label>
                                <textarea name="contract_terms" 
                                          class="form-control" 
                                          rows="5"
                                          placeholder="Enter the main terms and conditions of this contract..."></textarea>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Special Conditions</label>
                                <textarea name="special_conditions" 
                                          class="form-control" 
                                          rows="3"
                                          placeholder="Any special conditions or clauses..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Parties Information -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class="fas fa-users"></i>
                            <span>Section 2: Witnesses & Officials</span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Witness 1 Name</label>
                                <input type="text" 
                                       name="witness1_name" 
                                       class="form-control" 
                                       placeholder="Full name">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Witness 1 ID Number</label>
                                <input type="text" 
                                       name="witness1_id_number" 
                                       class="form-control" 
                                       placeholder="National ID or Passport">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Witness 2 Name</label>
                                <input type="text" 
                                       name="witness2_name" 
                                       class="form-control" 
                                       placeholder="Full name">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Witness 2 ID Number</label>
                                <input type="text" 
                                       name="witness2_id_number" 
                                       class="form-control" 
                                       placeholder="National ID or Passport">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Lawyer Name</label>
                                <input type="text" 
                                       name="lawyer_name" 
                                       class="form-control" 
                                       placeholder="Attorney name (if applicable)">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Notary Public Name</label>
                                <input type="text" 
                                       name="notary_name" 
                                       class="form-control" 
                                       placeholder="Notary name">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Notary Stamp Number</label>
                                <input type="text" 
                                       name="notary_stamp_number" 
                                       class="form-control" 
                                       placeholder="Official stamp number">
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Document Upload -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <i class="fas fa-upload"></i>
                            <span>Section 3: Upload Documents</span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Contract Template</label>
                                <label for="contract_template" class="file-upload-label">
                                    <i class="fas fa-cloud-upload-alt d-block"></i>
                                    <span>Click to upload contract template</span>
                                    <small class="d-block text-muted mt-2">PDF, DOC, or DOCX (Max 10MB)</small>
                                </label>
                                <input type="file" 
                                       name="contract_template" 
                                       id="contract_template"
                                       class="d-none"
                                       accept=".pdf,.doc,.docx"
                                       onchange="displayFileName(this, 'template_file_name')">
                                <div id="template_file_name" class="selected-file" style="display: none;"></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Signed Contract (if available)</label>
                                <label for="signed_contract" class="file-upload-label">
                                    <i class="fas fa-file-signature d-block"></i>
                                    <span>Click to upload signed contract</span>
                                    <small class="d-block text-muted mt-2">PDF only (Max 10MB)</small>
                                </label>
                                <input type="file" 
                                       name="signed_contract" 
                                       id="signed_contract"
                                       class="d-none"
                                       accept=".pdf"
                                       onchange="displayFileName(this, 'signed_file_name')">
                                <div id="signed_file_name" class="selected-file" style="display: none;"></div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="col-lg-4">
                    <!-- Reservation Info Card -->
                    <div class="info-card" id="reservation_info" style="display: none;">
                        <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Reservation Details</h5>
                        
                        <div class="info-item">
                            <span>Reservation #:</span>
                            <span id="info_reservation_number">—</span>
                        </div>
                        <div class="info-item">
                            <span>Customer:</span>
                            <span id="info_customer">—</span>
                        </div>
                        <div class="info-item">
                            <span>Phone:</span>
                            <span id="info_phone">—</span>
                        </div>
                        <div class="info-item">
                            <span>ID Number:</span>
                            <span id="info_id">—</span>
                        </div>
                        <div class="info-item">
                            <span>Plot:</span>
                            <span id="info_plot">—</span>
                        </div>
                        <div class="info-item">
                            <span>Total Amount:</span>
                            <span id="info_amount">TSH 0</span>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="form-section">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-save">
                                <i class="fas fa-save me-2"></i>Create Contract
                            </button>
                            <a href="contracts.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <?php endif; ?>

    </div>
</section>

<script>
function toggleDuration() {
    const contractType = document.getElementById('contract_type').value;
    const durationField = document.getElementById('duration_field');
    
    if (contractType === 'installment' || contractType === 'lease') {
        durationField.style.display = 'block';
    } else {
        durationField.style.display = 'none';
    }
}

function loadReservationDetails() {
    const select = document.getElementById('reservation_id');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        document.getElementById('reservation_info').style.display = 'block';
        document.getElementById('info_reservation_number').textContent = selectedOption.getAttribute('data-reservation-number');
        document.getElementById('info_customer').textContent = selectedOption.getAttribute('data-customer');
        document.getElementById('info_phone').textContent = selectedOption.getAttribute('data-phone');
        document.getElementById('info_id').textContent = selectedOption.getAttribute('data-id');
        document.getElementById('info_plot').textContent = selectedOption.getAttribute('data-plot');
        document.getElementById('info_amount').textContent = 'TSH ' + selectedOption.getAttribute('data-amount');
    } else {
        document.getElementById('reservation_info').style.display = 'none';
    }
}

function displayFileName(input, targetId) {
    const target = document.getElementById(targetId);
    if (input.files && input.files[0]) {
        target.style.display = 'block';
        target.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>' + input.files[0].name;
    } else {
        target.style.display = 'none';
    }
}
</script>

<?php 
require_once '../../includes/footer.php';
?>