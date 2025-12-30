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

// Get request ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = 'Invalid service request ID';
    header('Location: requests.php');
    exit;
}

$request_id = (int)$_GET['id'];

// Fetch service request details with all related data
try {
    $query = "
        SELECT 
            sr.*,
            st.service_name,
            st.service_code,
            st.service_category,
            st.base_price,
            st.price_unit,
            st.estimated_duration_days,
            c.full_name as customer_name,
            c.email as customer_email,
            c.phone as customer_phone,
            c.address as customer_address,
            p.plot_number,
            p.size as plot_size_db,
            p.block,
            proj.project_name,
            u.full_name as assigned_to_name,
            u.email as assigned_to_email,
            creator.full_name as created_by_name
        FROM service_requests sr
        INNER JOIN service_types st ON sr.service_type_id = st.service_type_id
        LEFT JOIN customers c ON sr.customer_id = c.customer_id
        LEFT JOIN plots p ON sr.plot_id = p.plot_id
        LEFT JOIN projects proj ON sr.project_id = proj.project_id
        LEFT JOIN users u ON sr.assigned_to = u.user_id
        LEFT JOIN users creator ON sr.created_by = creator.user_id
        WHERE sr.service_request_id = ? AND sr.company_id = ?
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute([$request_id, $company_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $_SESSION['error_message'] = 'Service request not found';
        header('Location: requests.php');
        exit;
    }

} catch (PDOException $e) {
    error_log("Error fetching service request: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error loading service request';
    header('Location: requests.php');
    exit;
}

// Calculate progress based on status
$progress = 0;
switch ($request['status']) {
    case 'pending':
        $progress = 10;
        break;
    case 'quoted':
        $progress = 30;
        break;
    case 'approved':
        $progress = 50;
        break;
    case 'in_progress':
        $progress = 75;
        break;
    case 'completed':
        $progress = 100;
        break;
    case 'cancelled':
    case 'on_hold':
        $progress = 0;
        break;
}

$page_title = 'View Service Request';
require_once '../../includes/header.php';
?>

<style>
.request-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.request-header {
    border-bottom: 3px solid #17a2b8;
    padding-bottom: 1rem;
    margin-bottom: 1.5rem;
}

.request-number {
    font-family: 'Courier New', monospace;
    font-size: 1.5rem;
    font-weight: 700;
    color: #17a2b8;
}

.info-section {
    margin-bottom: 2rem;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
}

.section-title i {
    margin-right: 10px;
    color: #17a2b8;
}

.info-row {
    display: flex;
    margin-bottom: 0.75rem;
    padding: 0.5rem 0;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    width: 180px;
    flex-shrink: 0;
}

.info-value {
    color: #2c3e50;
    flex: 1;
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    display: inline-block;
}

.status-badge.pending { background: #e9ecef; color: #495057; }
.status-badge.quoted { background: #cfe2ff; color: #084298; }
.status-badge.approved { background: #fff3cd; color: #856404; }
.status-badge.in_progress { background: #d1ecf1; color: #0c5460; }
.status-badge.completed { background: #d4edda; color: #155724; }
.status-badge.cancelled { background: #f8d7da; color: #721c24; }
.status-badge.on_hold { background: #e2e3e5; color: #383d41; }

.category-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

.category-badge.land_evaluation { background: #d1ecf1; color: #0c5460; }
.category-badge.title_processing { background: #cfe2ff; color: #084298; }
.category-badge.consultation { background: #e7e7ff; color: #3d3d99; }
.category-badge.construction { background: #fff3cd; color: #856404; }
.category-badge.survey { background: #d4edda; color: #155724; }
.category-badge.legal { background: #f8d7da; color: #721c24; }
.category-badge.other { background: #e9ecef; color: #495057; }

.price-display {
    font-size: 1.75rem;
    font-weight: 700;
    color: #28a745;
}

.price-label {
    font-size: 0.875rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.progress-container {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.progress {
    height: 30px;
    border-radius: 15px;
    background: #e9ecef;
    overflow: visible;
}

.progress-bar {
    border-radius: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: width 0.6s ease;
}

.timeline {
    margin: 2rem 0;
}

.timeline-item {
    padding: 1rem;
    border-left: 3px solid #e9ecef;
    margin-left: 20px;
    position: relative;
}

.timeline-item.completed {
    border-left-color: #28a745;
}

.timeline-item.current {
    border-left-color: #17a2b8;
    background: #f8f9fa;
}

.timeline-item:before {
    content: '';
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #e9ecef;
    border: 3px solid white;
    position: absolute;
    left: -11px;
    top: 20px;
}

.timeline-item.completed:before {
    background: #28a745;
}

.timeline-item.current:before {
    background: #17a2b8;
    box-shadow: 0 0 0 4px rgba(23, 162, 184, 0.2);
}

.timeline-title {
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.timeline-date {
    font-size: 0.875rem;
    color: #6c757d;
}

.description-box {
    background: #f8f9fa;
    border-left: 4px solid #17a2b8;
    padding: 1.5rem;
    border-radius: 8px;
    margin: 1rem 0;
}

.payment-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
}

.payment-badge.unpaid { background: #f8d7da; color: #721c24; }
.payment-badge.partial { background: #fff3cd; color: #856404; }
.payment-badge.paid { background: #d4edda; color: #155724; }

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.empty-value {
    color: #adb5bd;
    font-style: italic;
}

.highlight-box {
    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
}

.highlight-box .price-display {
    color: white;
    font-size: 2.5rem;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-clipboard-list text-info me-2"></i>Service Request Details
                </h1>
                <p class="text-muted small mb-0 mt-1">View and manage service request</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end action-buttons">
                    <a href="requests.php" class="btn btn-outline-secondary btn-action">
                        <i class="fas fa-arrow-left"></i> Back to Requests
                    </a>
                    <?php if ($request['status'] == 'pending'): ?>
                    <a href="edit-request.php?id=<?php echo $request_id; ?>" class="btn btn-warning btn-action">
                        <i class="fas fa-edit"></i> Edit Request
                    </a>
                    <?php endif; ?>
                    <button class="btn btn-info btn-action" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Request Header -->
        <div class="request-card">
            <div class="request-header">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="request-number"><?php echo htmlspecialchars($request['request_number']); ?></div>
                        <div class="mt-2">
                            <span class="status-badge <?php echo $request['status']; ?>">
                                <?php echo ucwords(str_replace('_', ' ', $request['status'])); ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="text-muted small">Request Date</div>
                        <div class="fw-bold"><?php echo date('F d, Y', strtotime($request['request_date'])); ?></div>
                        <div class="text-muted small mt-2">
                            Created by: <?php echo htmlspecialchars($request['created_by_name'] ?? 'System'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Bar -->
            <?php if (!in_array($request['status'], ['cancelled'])): ?>
            <div class="progress-container">
                <div class="d-flex justify-content-between mb-2">
                    <span class="fw-bold">Progress</span>
                    <span class="fw-bold text-info"><?php echo $progress; ?>%</span>
                </div>
                <div class="progress">
                    <div class="progress-bar bg-info" 
                         role="progressbar" 
                         style="width: <?php echo $progress; ?>%"
                         aria-valuenow="<?php echo $progress; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100">
                        <?php echo $progress; ?>%
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Service Information -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-concierge-bell"></i>
                    Service Information
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-label">Service Type:</div>
                            <div class="info-value">
                                <strong><?php echo htmlspecialchars($request['service_name']); ?></strong>
                                <span class="ms-2 text-muted">(<?php echo htmlspecialchars($request['service_code']); ?>)</span>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Category:</div>
                            <div class="info-value">
                                <span class="category-badge <?php echo $request['service_category']; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $request['service_category'])); ?>
                                </span>
                            </div>
                        </div>
                        <?php if (!empty($request['base_price'])): ?>
                        <div class="info-row">
                            <div class="info-label">Base Price:</div>
                            <div class="info-value">
                                <strong>TSH <?php echo number_format((float)$request['base_price'], 2); ?></strong>
                                <?php if (!empty($request['price_unit'])): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($request['price_unit']); ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php if (!empty($request['estimated_duration_days'])): ?>
                        <div class="info-row">
                            <div class="info-label">Est. Duration:</div>
                            <div class="info-value">
                                <i class="fas fa-clock text-info me-1"></i>
                                <?php echo $request['estimated_duration_days']; ?> days
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <div class="info-label">Assigned To:</div>
                            <div class="info-value">
                                <?php if (!empty($request['assigned_to_name'])): ?>
                                <i class="fas fa-user text-info me-1"></i>
                                <?php echo htmlspecialchars($request['assigned_to_name']); ?>
                                <?php else: ?>
                                <span class="empty-value">Unassigned</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($request['service_description'])): ?>
                <div class="description-box">
                    <div class="fw-bold mb-2">Service Description:</div>
                    <?php echo nl2br(htmlspecialchars($request['service_description'])); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Customer Information -->
            <?php if (!empty($request['customer_name'])): ?>
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-user-tie"></i>
                    Customer Information
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-label">Customer Name:</div>
                            <div class="info-value">
                                <strong><?php echo htmlspecialchars($request['customer_name']); ?></strong>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Phone:</div>
                            <div class="info-value">
                                <?php if (!empty($request['customer_phone'])): ?>
                                <i class="fas fa-phone text-success me-1"></i>
                                <?php echo htmlspecialchars($request['customer_phone']); ?>
                                <?php else: ?>
                                <span class="empty-value">Not provided</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-label">Email:</div>
                            <div class="info-value">
                                <?php if (!empty($request['customer_email'])): ?>
                                <i class="fas fa-envelope text-info me-1"></i>
                                <?php echo htmlspecialchars($request['customer_email']); ?>
                                <?php else: ?>
                                <span class="empty-value">Not provided</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Address:</div>
                            <div class="info-value">
                                <?php echo !empty($request['customer_address']) ? nl2br(htmlspecialchars($request['customer_address'])) : '<span class="empty-value">Not provided</span>'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Project & Plot Information -->
            <?php if (!empty($request['project_name']) || !empty($request['plot_number'])): ?>
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-map-marked-alt"></i>
                    Location Information
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <?php if (!empty($request['project_name'])): ?>
                        <div class="info-row">
                            <div class="info-label">Project:</div>
                            <div class="info-value">
                                <strong><?php echo htmlspecialchars($request['project_name']); ?></strong>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($request['plot_number'])): ?>
                        <div class="info-row">
                            <div class="info-label">Plot:</div>
                            <div class="info-value">
                                <strong>Plot #<?php echo htmlspecialchars($request['plot_number']); ?></strong>
                                <?php if (!empty($request['block'])): ?>
                                <span class="text-muted">(Block <?php echo htmlspecialchars($request['block']); ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php if (!empty($request['plot_size'])): ?>
                        <div class="info-row">
                            <div class="info-label">Plot Size:</div>
                            <div class="info-value">
                                <?php echo number_format((float)$request['plot_size'], 2); ?> sqm
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($request['location_details'])): ?>
                        <div class="info-row">
                            <div class="info-label">Location Details:</div>
                            <div class="info-value">
                                <?php echo nl2br(htmlspecialchars($request['location_details'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pricing & Payment -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-money-bill-wave"></i>
                    Pricing & Payment
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-center p-3 bg-light rounded">
                            <div class="price-label">Quoted Price</div>
                            <?php if (!empty($request['quoted_price'])): ?>
                            <div class="price-display">TSH <?php echo number_format((float)$request['quoted_price'], 0); ?></div>
                            <?php else: ?>
                            <div class="text-muted">Pending Quote</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 bg-light rounded">
                            <div class="price-label">Final Price</div>
                            <?php if (!empty($request['final_price'])): ?>
                            <div class="price-display">TSH <?php echo number_format((float)$request['final_price'], 0); ?></div>
                            <?php else: ?>
                            <div class="text-muted">Not Set</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 bg-light rounded">
                            <div class="price-label">Payment Status</div>
                            <div class="mt-2">
                                <span class="payment-badge <?php echo $request['payment_status']; ?>">
                                    <?php echo ucfirst($request['payment_status']); ?>
                                </span>
                            </div>
                            <?php if ($request['payment_status'] == 'partial' && !empty($request['amount_paid'])): ?>
                            <div class="small text-muted mt-2">
                                Paid: TSH <?php echo number_format((float)$request['amount_paid'], 0); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedule Information -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-calendar-alt"></i>
                    Schedule Information
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-label">Requested Start:</div>
                            <div class="info-value">
                                <?php echo !empty($request['requested_start_date']) ? date('F d, Y', strtotime($request['requested_start_date'])) : '<span class="empty-value">Not specified</span>'; ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Actual Start:</div>
                            <div class="info-value">
                                <?php echo !empty($request['actual_start_date']) ? date('F d, Y', strtotime($request['actual_start_date'])) : '<span class="empty-value">Not started</span>'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-label">Expected Completion:</div>
                            <div class="info-value">
                                <?php echo !empty($request['expected_completion_date']) ? date('F d, Y', strtotime($request['expected_completion_date'])) : '<span class="empty-value">Not set</span>'; ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Actual Completion:</div>
                            <div class="info-value">
                                <?php echo !empty($request['actual_completion_date']) ? date('F d, Y', strtotime($request['actual_completion_date'])) : '<span class="empty-value">Not completed</span>'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Remarks -->
            <?php if (!empty($request['remarks'])): ?>
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-sticky-note"></i>
                    Remarks / Notes
                </div>
                <div class="description-box">
                    <?php echo nl2br(htmlspecialchars($request['remarks'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status Timeline -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-tasks"></i>
                    Status Timeline
                </div>
                <div class="timeline">
                    <div class="timeline-item <?php echo in_array($request['status'], ['pending', 'quoted', 'approved', 'in_progress', 'completed']) ? 'completed' : ''; ?> <?php echo $request['status'] == 'pending' ? 'current' : ''; ?>">
                        <div class="timeline-title">Request Submitted</div>
                        <div class="timeline-date">
                            <?php echo date('F d, Y', strtotime($request['created_at'])); ?>
                        </div>
                    </div>
                    
                    <div class="timeline-item <?php echo in_array($request['status'], ['quoted', 'approved', 'in_progress', 'completed']) ? 'completed' : ''; ?> <?php echo $request['status'] == 'quoted' ? 'current' : ''; ?>">
                        <div class="timeline-title">Quotation Provided</div>
                        <div class="timeline-date">
                            <?php echo in_array($request['status'], ['quoted', 'approved', 'in_progress', 'completed']) && !empty($request['updated_at']) ? date('F d, Y', strtotime($request['updated_at'])) : 'Pending'; ?>
                        </div>
                    </div>
                    
                    <div class="timeline-item <?php echo in_array($request['status'], ['approved', 'in_progress', 'completed']) ? 'completed' : ''; ?> <?php echo $request['status'] == 'approved' ? 'current' : ''; ?>">
                        <div class="timeline-title">Approved by Customer</div>
                        <div class="timeline-date">
                            <?php echo in_array($request['status'], ['approved', 'in_progress', 'completed']) && !empty($request['updated_at']) ? date('F d, Y', strtotime($request['updated_at'])) : 'Pending'; ?>
                        </div>
                    </div>
                    
                    <div class="timeline-item <?php echo in_array($request['status'], ['in_progress', 'completed']) ? 'completed' : ''; ?> <?php echo $request['status'] == 'in_progress' ? 'current' : ''; ?>">
                        <div class="timeline-title">Work in Progress</div>
                        <div class="timeline-date">
                            <?php echo !empty($request['actual_start_date']) ? date('F d, Y', strtotime($request['actual_start_date'])) : 'Not started'; ?>
                        </div>
                    </div>
                    
                    <div class="timeline-item <?php echo $request['status'] == 'completed' ? 'completed current' : ''; ?>">
                        <div class="timeline-title">Completed</div>
                        <div class="timeline-date">
                            <?php echo !empty($request['actual_completion_date']) ? date('F d, Y', strtotime($request['actual_completion_date'])) : 'Pending'; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</section>

<?php 
require_once '../../includes/footer.php';
?>