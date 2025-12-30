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

// Fetch statistics with improved error handling
try {
    $stats_query = "
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_leads,
            SUM(CASE WHEN status = 'contacted' THEN 1 ELSE 0 END) as contacted_leads,
            SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_leads,
            SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_leads,
            COALESCE(SUM(estimated_value), 0) as total_pipeline_value,
            COALESCE(AVG(estimated_value), 0) as avg_lead_value
        FROM leads
        WHERE company_id = ? AND is_active = 1
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Ensure all stats are set with default values
    $stats = array_merge([
        'total_leads' => 0,
        'new_leads' => 0,
        'contacted_leads' => 0,
        'qualified_leads' => 0,
        'converted_leads' => 0,
        'lost_leads' => 0,
        'total_pipeline_value' => 0,
        'avg_lead_value' => 0
    ], $stats);

    // Calculate conversion rate safely
    $total = (int)$stats['total_leads'];
    $converted = (int)$stats['converted_leads'];
    $conversion_rate = $total > 0 ? round(($converted / $total) * 100, 1) : 0;

} catch (PDOException $e) {
    error_log("Error fetching lead stats: " . $e->getMessage());
    $stats = [
        'total_leads' => 0,
        'new_leads' => 0,
        'contacted_leads' => 0,
        'qualified_leads' => 0,
        'converted_leads' => 0,
        'lost_leads' => 0,
        'total_pipeline_value' => 0,
        'avg_lead_value' => 0
    ];
    $conversion_rate = 0;
}

// Fetch sources for filter dropdown
$sources = [
    'website' => 'Website',
    'referral' => 'Referral',
    'social_media' => 'Social Media',
    'email_campaign' => 'Email Campaign',
    'cold_call' => 'Cold Call',
    'event' => 'Event',
    'advertisement' => 'Advertisement',
    'partner' => 'Partner',
    'other' => 'Other'
];

// Build filter conditions safely
$where_conditions = ["l.company_id = ? AND l.is_active = 1"];
$params = [$company_id];

if (!empty($_GET['status'])) {
    $where_conditions[] = "l.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['source'])) {
    $where_conditions[] = "l.source = ?";
    $params[] = $_GET['source'];
}

if (!empty($_GET['assigned_to'])) {
    $where_conditions[] = "l.assigned_to = ?";
    $params[] = $_GET['assigned_to'];
}

if (!empty($_GET['date_from'])) {
    $where_conditions[] = "l.created_at >= ?";
    $params[] = $_GET['date_from'] . ' 00:00:00';
}

if (!empty($_GET['date_to'])) {
    $where_conditions[] = "l.created_at <= ?";
    $params[] = $_GET['date_to'] . ' 23:59:59';
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(l.company_name LIKE ? OR l.contact_person LIKE ? OR l.email LIKE ? OR l.phone LIKE ?)";
    $search = '%' . trim($_GET['search']) . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch leads with improved JOIN and error handling
try {
    $leads_query = "
        SELECT 
            l.*,
            u.full_name as assigned_to_name
        FROM leads l
        LEFT JOIN users u ON l.assigned_to = u.user_id
        WHERE $where_clause
        ORDER BY l.created_at DESC
    ";
    $stmt = $conn->prepare($leads_query);
    $stmt->execute($params);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching leads: " . $e->getMessage());
    $leads = [];
}

// Fetch users for filter dropdown
try {
    $users_query = "SELECT user_id, full_name FROM users WHERE company_id = ? AND is_active = 1 ORDER BY full_name";
    $stmt = $conn->prepare($users_query);
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $users = [];
}

$page_title = 'Leads Management';
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
    position: relative;
    overflow: hidden;
}

.stats-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--bs-primary), var(--bs-info));
}

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
.stats-card.info { border-left-color: #17a2b8; }
.stats-card.danger { border-left-color: #dc3545; }

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
    line-height: 1.2;
}

.stats-label {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 0.25rem;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.table-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.status-badge {
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: capitalize;
}

.status-badge.new { background: #cfe2ff; color: #084298; }
.status-badge.contacted { background: #d1ecf1; color: #0c5460; }
.status-badge.qualified { background: #fff3cd; color: #856404; }
.status-badge.proposal { background: #d4edda; color: #155724; }
.status-badge.negotiation { background: #e2e3e5; color: #383d41; }
.status-badge.converted { background: #d4edda; color: #155724; }
.status-badge.lost { background: #f8d7da; color: #721c24; }

.source-badge {
    padding: 0.3rem 0.6rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    background: #e9ecef;
    color: #495057;
}

.lead-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.lead-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.value-highlight {
    font-weight: 700;
    color: #28a745;
    font-size: 0.95rem;
}

.action-btn {
    padding: 0.4rem 0.6rem;
    font-size: 0.85rem;
    border-radius: 6px;
    transition: all 0.2s;
}

.action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    opacity: 0.5;
    margin-bottom: 1rem;
}

@media (max-width: 768px) {
    .stats-number { font-size: 1.5rem; }
    .lead-info { flex-direction: column; align-items: flex-start; gap: 6px; }
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-users text-primary me-2"></i>Leads Management
                </h1>
                <p class="text-muted small mb-0 mt-1">Track, manage, and convert sales leads</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="campaigns.php" class="btn btn-outline-info me-2">
                        <i class="fas fa-bullhorn me-1"></i> Campaigns
                    </a>
                    <a href="create-lead.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-1"></i> <span class="d-none d-sm-inline">New Lead</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_leads']); ?></div>
                    <div class="stats-label">Total Leads</div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number"><?php echo number_format((int)$stats['new_leads']); ?></div>
                    <div class="stats-label">New</div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format((int)$stats['qualified_leads']); ?></div>
                    <div class="stats-label">Qualified</div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['converted_leads']); ?></div>
                    <div class="stats-label">Converted</div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-6">
                <div class="stats-card danger">
                    <div class="stats-number"><?php echo number_format((int)$stats['lost_leads']); ?></div>
                    <div class="stats-label">Lost</div>
                </div>
            </div>
            <div class="col-xl-2 col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo $conversion_rate; ?>%</div>
                    <div class="stats-label">Conversion Rate</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number">
                        TSH <?php echo number_format((float)$stats['total_pipeline_value'] / 1000000, 1); ?>M
                    </div>
                    <div class="stats-label">Total Pipeline Value</div>
                </div>
            </div>
            <div class="col-lg-6 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number">
                        TSH <?php echo number_format((float)$stats['avg_lead_value'] / 1000, 0); ?>K
                    </div>
                    <div class="stats-label">Avg Lead Value</div>
                </div>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="filter-card">
            <h5 class="mb-3"><i class="fas fa-filter me-2 text-primary"></i>Advanced Filters</h5>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold">Search Leads</label>
                    <input type="text" 
                           name="search" 
                           class="form-control" 
                           placeholder="Company, contact, email or phone..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="new" <?php echo ($_GET['status'] ?? '') === 'new' ? 'selected' : ''; ?>>New</option>
                        <option value="contacted" <?php echo ($_GET['status'] ?? '') === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                        <option value="qualified" <?php echo ($_GET['status'] ?? '') === 'qualified' ? 'selected' : ''; ?>>Qualified</option>
                        <option value="proposal" <?php echo ($_GET['status'] ?? '') === 'proposal' ? 'selected' : ''; ?>>Proposal</option>
                        <option value="negotiation" <?php echo ($_GET['status'] ?? '') === 'negotiation' ? 'selected' : ''; ?>>Negotiation</option>
                        <option value="converted" <?php echo ($_GET['status'] ?? '') === 'converted' ? 'selected' : ''; ?>>Converted</option>
                        <option value="lost" <?php echo ($_GET['status'] ?? '') === 'lost' ? 'selected' : ''; ?>>Lost</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold">Source</label>
                    <select name="source" class="form-select">
                        <option value="">All Sources</option>
                        <?php foreach ($sources as $value => $label): ?>
                        <option value="<?php echo $value; ?>" 
                                <?php echo ($_GET['source'] ?? '') === $value ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold">Assigned To</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">All Users</option>
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>" 
                                <?php echo ($_GET['assigned_to'] ?? '') == $user['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold">Date Range</label>
                    <div class="row g-2">
                        <div class="col-6">
                            <input type="date" 
                                   name="date_from" 
                                   class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                        </div>
                        <div class="col-6">
                            <input type="date" 
                                   name="date_to" 
                                   class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="col-lg-1 col-md-6">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                </div>
            </form>
            <div class="mt-3">
                <a href="leads.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-redo me-1"></i> Reset All Filters
                </a>
                <?php if (!empty(array_filter($_GET))): ?>
                <span class="badge bg-primary ms-2">
                    <?php 
                    $active_filters = count(array_filter($_GET, fn($v) => !empty(trim($v))));
                    echo $active_filters; ?> filter<?php echo $active_filters !== 1 ? 's' : ''; ?> active
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Leads Table -->
        <div class="table-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2 text-primary"></i>
                    Leads List 
                    <span class="badge bg-light text-dark fs-6">
                        <?php echo count($leads); ?> leads
                    </span>
                </h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-success btn-sm" onclick="exportLeads()">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                    <a href="create-lead.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-1"></i> New Lead
                    </a>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="leadsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 120px;">Date Created</th>
                            <th style="width: 250px;">Lead Information</th>
                            <th style="width: 200px;">Contact Details</th>
                            <th style="width: 120px;">Source</th>
                            <th style="width: 150px;">Assigned To</th>
                            <th style="width: 120px;">Est. Value</th>
                            <th style="width: 120px;">Status</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                <i class="fas fa-users-slash"></i>
                                <h5 class="mt-3">No leads found</h5>
                                <p class="mb-4">Get started by creating your first lead or adjust your filters.</p>
                                <div class="d-flex flex-wrap justify-content-center gap-3">
                                    <a href="create-lead.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i>Create First Lead
                                    </a>
                                    <a href="leads.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-redo me-2"></i>Clear Filters
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td>
                                <div class="small">
                                    <div class="fw-semibold text-primary">
                                        <?php echo date('M d', strtotime($lead['created_at'])); ?>
                                    </div>
                                    <div class="text-muted">
                                        <?php echo date('Y', strtotime($lead['created_at'])); ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="lead-info">
                                    <div class="lead-avatar">
                                        <?php 
                                        $initials = strtoupper(
                                            substr($lead['company_name'] ?? '', 0, 1) . 
                                            substr($lead['contact_person'] ?? '', 0, 1)
                                        );
                                        echo $initials ?: 'L';
                                        ?>
                                    </div>
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-semibold" style="max-width: 200px;" title="<?php echo htmlspecialchars($lead['company_name']); ?>">
                                            <?php echo htmlspecialchars($lead['company_name'] ?: 'N/A'); ?>
                                        </div>
                                        <div class="text-muted small" style="max-width: 200px;" title="<?php echo htmlspecialchars($lead['contact_person']); ?>">
                                            <?php echo htmlspecialchars($lead['contact_person'] ?: 'No contact'); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <?php if (!empty($lead['email'])): ?>
                                    <div class="mb-1">
                                        <i class="fas fa-envelope text-info me-1"></i>
                                        <a href="mailto:<?php echo htmlspecialchars($lead['email']); ?>" 
                                           class="text-decoration-none" 
                                           title="Email">
                                            <?php echo htmlspecialchars(substr($lead['email'], 0, 25)) . (strlen($lead['email']) > 25 ? '...' : ''); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($lead['phone'])): ?>
                                    <div>
                                        <i class="fas fa-phone text-success me-1"></i>
                                        <a href="tel:<?php echo htmlspecialchars($lead['phone']); ?>" 
                                           class="text-decoration-none" 
                                           title="Call">
                                            <?php echo htmlspecialchars($lead['phone']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="source-badge">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $lead['source'] ?? 'Unknown'))); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($lead['assigned_to_name'])): ?>
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-user-tie text-primary me-2"></i>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($lead['assigned_to_name']); ?></span>
                                </div>
                                <?php else: ?>
                                <span class="badge bg-secondary">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($lead['estimated_value']) && $lead['estimated_value'] > 0): ?>
                                <span class="value-highlight fw-semibold">
                                    TSH <?php echo number_format((float)$lead['estimated_value'], 0); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo htmlspecialchars($lead['status'] ?? 'new'); ?>">
                                    <?php echo ucfirst(htmlspecialchars($lead['status'] ?? 'new')); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="view-lead.php?id=<?php echo (int)$lead['lead_id']; ?>" 
                                       class="btn btn-outline-primary action-btn"
                                       title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit-lead.php?id=<?php echo (int)$lead['lead_id']; ?>" 
                                       class="btn btn-outline-warning action-btn"
                                       title="Edit Lead">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if (in_array($lead['status'] ?? '', ['qualified', 'proposal', 'negotiation'])): ?>
                                    <a href="create-quotation.php?lead_id=<?php echo (int)$lead['lead_id']; ?>" 
                                       class="btn btn-outline-success action-btn"
                                       title="Create Quotation">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-danger action-btn" 
                                            title="Delete Lead"
                                            onclick="confirmDelete(<?php echo (int)$lead['lead_id']; ?>, '<?php echo addslashes($lead['company_name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>

<!-- DataTables CSS & JS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable only if there are leads
    const table = $('#leadsTable');
    const hasDataRows = table.find('tbody tr').length > 0 && 
                       !table.find('tbody tr td[colspan]').length;
    
    if (hasDataRows) {
        table.DataTable({
            pageLength: 25,
            responsive: true,
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [-1, 3] }, // Disable ordering on Actions and Source
                { searchable: false, targets: [-1] }    // Disable search on Actions
            ],
            language: {
                search: "",
                searchPlaceholder: "Quick search...",
                lengthMenu: "Show _MENU_ leads per page",
                info: "Showing _START_ to _END_ of _TOTAL_ leads",
                infoEmpty: "No leads found",
                infoFiltered: "(filtered from _MAX_ total leads)",
                paginate: {
                    first: "«",
                    last: "»",
                    next: "›",
                    previous: "‹"
                }
            },
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
        });
    }
});

// Delete confirmation
function confirmDelete(leadId, companyName) {
    if (confirm(`Are you sure you want to delete the lead "${companyName}"? This action cannot be undone.`)) {
        window.location.href = `delete-lead.php?id=${leadId}`;
    }
}

// Export leads to CSV
function exportLeads() {
    const filters = new URLSearchParams(window.location.search);
    const url = `export-leads.php?${filters.toString()}`;
    window.open(url, '_blank');
}
</script>

<?php require_once '../../includes/footer.php'; ?>