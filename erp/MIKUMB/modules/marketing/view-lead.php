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

// Get lead ID from URL
$lead_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$lead_id) {
    header('Location: leads.php');
    exit;
}

// Fetch lead details
try {
    $lead_query = "
        SELECT 
            l.*,
            c.customer_id,
            c.full_name as converted_customer_name,
            u.full_name as assigned_to_name
        FROM leads l
        LEFT JOIN customers c ON l.converted_to_customer_id = c.customer_id
        LEFT JOIN users u ON l.assigned_to = u.user_id
        WHERE l.lead_id = ? AND l.company_id = ?
    ";
    $stmt = $conn->prepare($lead_query);
    $stmt->execute([$lead_id, $company_id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lead) {
        header('Location: leads.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching lead: " . $e->getMessage());
    $errors[] = "Failed to load lead details.";
}

// Fetch campaign if linked
$campaign = null;
if (!empty($lead['campaign_id'])) {
    try {
        $campaign_query = "SELECT * FROM campaigns WHERE campaign_id = ? AND company_id = ?";
        $stmt = $conn->prepare($campaign_query);
        $stmt->execute([$lead['campaign_id'], $company_id]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching campaign: " . $e->getMessage());
    }
}

// Fetch all users for assignment dropdown
try {
    $users_query = "SELECT user_id, full_name FROM users WHERE company_id = ? AND is_active = 1 ORDER BY full_name";
    $stmt = $conn->prepare($users_query);
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $users = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update lead status
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $new_status = $_POST['lead_status'] ?? '';
        $notes = trim($_POST['follow_up_notes'] ?? '');
        
        try {
            $update_query = "
                UPDATE leads 
                SET lead_status = ?,
                    follow_up_notes = ?,
                    last_contact_date = CURDATE(),
                    updated_at = NOW()
                WHERE lead_id = ? AND company_id = ?
            ";
            $stmt = $conn->prepare($update_query);
            $stmt->execute([$new_status, $notes, $lead_id, $company_id]);
            
            $success = "Lead status updated successfully!";
            
            // Refresh lead data
            $stmt = $conn->prepare($lead_query);
            $stmt->execute([$lead_id, $company_id]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error updating lead status: " . $e->getMessage());
            $errors[] = "Failed to update lead status.";
        }
    }
    
    // Update assignment
    if (isset($_POST['action']) && $_POST['action'] === 'update_assignment') {
        $assigned_to = intval($_POST['assigned_to'] ?? 0);
        
        try {
            $update_query = "
                UPDATE leads 
                SET assigned_to = ?,
                    updated_at = NOW()
                WHERE lead_id = ? AND company_id = ?
            ";
            $stmt = $conn->prepare($update_query);
            $stmt->execute([$assigned_to > 0 ? $assigned_to : null, $lead_id, $company_id]);
            
            $success = "Assignment updated successfully!";
            
            // Refresh lead data
            $stmt = $conn->prepare($lead_query);
            $stmt->execute([$lead_id, $company_id]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error updating assignment: " . $e->getMessage());
            $errors[] = "Failed to update assignment.";
        }
    }
    
    // Schedule follow-up
    if (isset($_POST['action']) && $_POST['action'] === 'schedule_followup') {
        $next_follow_up = $_POST['next_follow_up_date'] ?? '';
        $notes = trim($_POST['follow_up_notes'] ?? '');
        
        try {
            $update_query = "
                UPDATE leads 
                SET next_follow_up_date = ?,
                    follow_up_notes = ?,
                    updated_at = NOW()
                WHERE lead_id = ? AND company_id = ?
            ";
            $stmt = $conn->prepare($update_query);
            $stmt->execute([$next_follow_up, $notes, $lead_id, $company_id]);
            
            $success = "Follow-up scheduled successfully!";
            
            // Refresh lead data
            $stmt = $conn->prepare($lead_query);
            $stmt->execute([$lead_id, $company_id]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error scheduling follow-up: " . $e->getMessage());
            $errors[] = "Failed to schedule follow-up.";
        }
    }
}

// Helper function for status badges
function getStatusBadge($status) {
    $badges = [
        'new' => 'badge bg-primary',
        'contacted' => 'badge bg-info',
        'qualified' => 'badge bg-warning',
        'proposal' => 'badge bg-purple',
        'negotiation' => 'badge bg-orange',
        'won' => 'badge bg-success',
        'lost' => 'badge bg-danger'
    ];
    return $badges[$status] ?? 'badge bg-secondary';
}

// Helper function for source icons
function getSourceIcon($source) {
    $icons = [
        'website' => 'fa-globe',
        'referral' => 'fa-user-friends',
        'walk_in' => 'fa-walking',
        'phone' => 'fa-phone',
        'email' => 'fa-envelope',
        'social_media' => 'fa-share-alt',
        'advertisement' => 'fa-bullhorn',
        'other' => 'fa-question-circle'
    ];
    return $icons[$source] ?? 'fa-circle';
}

$page_title = 'Lead Details';
require_once '../../includes/header.php';
?>

<style>
.detail-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 1.5rem;
}

.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
    margin-bottom: 1.5rem;
}

.detail-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
    display: flex;
    align-items: center;
}

.detail-title i {
    margin-right: 10px;
    color: #17a2b8;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f8f9fa;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #6c757d;
    width: 180px;
    flex-shrink: 0;
}

.info-value {
    color: #2c3e50;
    flex-grow: 1;
}

.status-timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -23px;
    top: 8px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #17a2b8;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #17a2b8;
}

.timeline-item::after {
    content: '';
    position: absolute;
    left: -17px;
    top: 20px;
    width: 2px;
    height: calc(100% - 8px);
    background: #dee2e6;
}

.timeline-item:last-child::after {
    display: none;
}

.action-btn {
    padding: 0.5rem 1rem;
    font-weight: 600;
    border-radius: 6px;
}

.bg-purple {
    background-color: #6f42c1;
}

.bg-orange {
    background-color: #fd7e14;
}

.quick-action-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.quick-action-card .btn {
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    font-weight: 600;
}

.quick-action-card .btn:hover {
    background: rgba(255,255,255,0.3);
    border-color: white;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-user-tie text-info me-2"></i>Lead Details
                </h1>
                <p class="text-muted small mb-0 mt-1"><?php echo htmlspecialchars($lead['full_name']); ?></p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="leads.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Leads
                    </a>
                    <a href="edit-lead.php?id=<?php echo $lead_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit me-1"></i> Edit Lead
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        
        <!-- Success/Error Messages -->
        <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                
                <!-- Lead Information -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h5 class="detail-title">
                            <i class="fas fa-user"></i>
                            Lead Information
                        </h5>
                        <span class="<?php echo getStatusBadge($lead['lead_status']); ?>">
                            <?php echo ucfirst($lead['lead_status']); ?>
                        </span>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Full Name:</div>
                        <div class="info-value"><strong><?php echo htmlspecialchars($lead['full_name']); ?></strong></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value">
                            <?php if (!empty($lead['email'])): ?>
                                <a href="mailto:<?php echo htmlspecialchars($lead['email']); ?>">
                                    <i class="fas fa-envelope me-1"></i>
                                    <?php echo htmlspecialchars($lead['email']); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">Not provided</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Phone:</div>
                        <div class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($lead['phone']); ?>">
                                <i class="fas fa-phone me-1"></i>
                                <?php echo htmlspecialchars($lead['phone']); ?>
                            </a>
                        </div>
                    </div>
                    
                    <?php if (!empty($lead['alternative_phone'])): ?>
                    <div class="info-row">
                        <div class="info-label">Alternative Phone:</div>
                        <div class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($lead['alternative_phone']); ?>">
                                <i class="fas fa-phone me-1"></i>
                                <?php echo htmlspecialchars($lead['alternative_phone']); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-row">
                        <div class="info-label">Lead Source:</div>
                        <div class="info-value">
                            <i class="fas <?php echo getSourceIcon($lead['lead_source']); ?> me-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $lead['lead_source'])); ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Created:</div>
                        <div class="info-value">
                            <?php echo date('M d, Y', strtotime($lead['created_at'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Interest Details -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h5 class="detail-title">
                            <i class="fas fa-bullseye"></i>
                            Interest Details
                        </h5>
                    </div>
                    
                    <?php if (!empty($lead['interested_in'])): ?>
                    <div class="info-row">
                        <div class="info-label">Interested In:</div>
                        <div class="info-value">
                            <span class="badge bg-info">
                                <?php echo ucfirst(str_replace('_', ' ', $lead['interested_in'])); ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($lead['budget_range'])): ?>
                    <div class="info-row">
                        <div class="info-label">Budget Range:</div>
                        <div class="info-value"><?php echo htmlspecialchars($lead['budget_range']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($lead['preferred_location'])): ?>
                    <div class="info-row">
                        <div class="info-label">Preferred Location:</div>
                        <div class="info-value"><?php echo htmlspecialchars($lead['preferred_location']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($lead['preferred_plot_size'])): ?>
                    <div class="info-row">
                        <div class="info-label">Preferred Plot Size:</div>
                        <div class="info-value"><?php echo htmlspecialchars($lead['preferred_plot_size']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Campaign Information -->
                <?php if ($campaign): ?>
                <div class="detail-card">
                    <div class="detail-header">
                        <h5 class="detail-title">
                            <i class="fas fa-bullhorn"></i>
                            Campaign Source
                        </h5>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Campaign Name:</div>
                        <div class="info-value">
                            <a href="view-campaign.php?id=<?php echo $campaign['campaign_id']; ?>">
                                <?php echo htmlspecialchars($campaign['campaign_name']); ?>
                            </a>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Campaign Type:</div>
                        <div class="info-value">
                            <?php echo ucfirst(str_replace('_', ' ', $campaign['campaign_type'])); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Follow-up Notes -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h5 class="detail-title">
                            <i class="fas fa-clipboard"></i>
                            Follow-up Notes
                        </h5>
                    </div>
                    
                    <?php if (!empty($lead['follow_up_notes'])): ?>
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($lead['follow_up_notes'])); ?></p>
                    <?php else: ?>
                        <p class="text-muted mb-0">No follow-up notes yet.</p>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                
                <!-- Quick Actions -->
                <div class="quick-action-card">
                    <h5 class="mb-3">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </h5>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                            <i class="fas fa-edit me-1"></i> Update Status
                        </button>
                        <button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#scheduleFollowupModal">
                            <i class="fas fa-calendar-plus me-1"></i> Schedule Follow-up
                        </button>
                        <a href="convert-lead.php?id=<?php echo $lead_id; ?>" class="btn">
                            <i class="fas fa-user-check me-1"></i> Convert to Customer
                        </a>
                    </div>
                </div>

                <!-- Assignment -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h5 class="detail-title">
                            <i class="fas fa-user-tag"></i>
                            Assignment
                        </h5>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_assignment">
                        <div class="mb-3">
                            <label class="form-label">Assigned To:</label>
                            <select name="assigned_to" class="form-select" onchange="this.form.submit()">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>"
                                            <?php echo ($lead['assigned_to'] == $user['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                    
                    <?php if (!empty($lead['assigned_to_name'])): ?>
                        <p class="text-muted small mb-0">
                            Currently assigned to: <strong><?php echo htmlspecialchars($lead['assigned_to_name']); ?></strong>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Timeline -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h5 class="detail-title">
                            <i class="fas fa-history"></i>
                            Timeline
                        </h5>
                    </div>
                    
                    <div class="status-timeline">
                        <?php if (!empty($lead['last_contact_date'])): ?>
                        <div class="timeline-item">
                            <small class="text-muted">Last Contact</small>
                            <div class="fw-bold"><?php echo date('M d, Y', strtotime($lead['last_contact_date'])); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($lead['next_follow_up_date'])): ?>
                        <div class="timeline-item">
                            <small class="text-muted">Next Follow-up</small>
                            <div class="fw-bold"><?php echo date('M d, Y', strtotime($lead['next_follow_up_date'])); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="timeline-item">
                            <small class="text-muted">Lead Created</small>
                            <div class="fw-bold"><?php echo date('M d, Y', strtotime($lead['created_at'])); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Conversion Status -->
                <?php if ($lead['lead_status'] === 'won' && !empty($lead['converted_to_customer_id'])): ?>
                <div class="detail-card">
                    <div class="detail-header">
                        <h5 class="detail-title">
                            <i class="fas fa-check-circle text-success"></i>
                            Conversion Status
                        </h5>
                    </div>
                    
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i>
                        Successfully converted to customer
                        <div class="mt-2">
                            <a href="view-customer.php?id=<?php echo $lead['converted_to_customer_id']; ?>" class="btn btn-sm btn-success">
                                View Customer Profile
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</section>

<!-- Update Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Update Lead Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_status">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Lead Status</label>
                        <select name="lead_status" class="form-select" required>
                            <option value="new" <?php echo ($lead['lead_status'] == 'new') ? 'selected' : ''; ?>>New</option>
                            <option value="contacted" <?php echo ($lead['lead_status'] == 'contacted') ? 'selected' : ''; ?>>Contacted</option>
                            <option value="qualified" <?php echo ($lead['lead_status'] == 'qualified') ? 'selected' : ''; ?>>Qualified</option>
                            <option value="proposal" <?php echo ($lead['lead_status'] == 'proposal') ? 'selected' : ''; ?>>Proposal</option>
                            <option value="negotiation" <?php echo ($lead['lead_status'] == 'negotiation') ? 'selected' : ''; ?>>Negotiation</option>
                            <option value="won" <?php echo ($lead['lead_status'] == 'won') ? 'selected' : ''; ?>>Won</option>
                            <option value="lost" <?php echo ($lead['lead_status'] == 'lost') ? 'selected' : ''; ?>>Lost</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="follow_up_notes" class="form-control" rows="4"><?php echo htmlspecialchars($lead['follow_up_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Schedule Follow-up Modal -->
<div class="modal fade" id="scheduleFollowupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-plus me-2"></i>Schedule Follow-up
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="schedule_followup">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Follow-up Date</label>
                        <input type="date" 
                               name="next_follow_up_date" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($lead['next_follow_up_date'] ?? ''); ?>"
                               min="<?php echo date('Y-m-d'); ?>"
                               required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Follow-up Notes</label>
                        <textarea name="follow_up_notes" class="form-control" rows="4"><?php echo htmlspecialchars($lead['follow_up_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Schedule Follow-up</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php 
require_once '../../includes/footer.php';
?>