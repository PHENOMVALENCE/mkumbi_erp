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

// Fetch statistics
try {
    $stats_query = "
        SELECT 
            COUNT(*) as total_campaigns,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_campaigns,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_campaigns,
            COALESCE(SUM(budget), 0) as total_budget,
            COALESCE(SUM(actual_cost), 0) as total_spent
        FROM campaigns
        WHERE company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get leads from campaigns
    $leads_query = "
        SELECT 
            COUNT(*) as total_campaign_leads,
            SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_leads
        FROM leads
        WHERE company_id = ? AND campaign_id IS NOT NULL
    ";
    $stmt = $conn->prepare($leads_query);
    $stmt->execute([$company_id]);
    $leads_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = array_merge($stats, $leads_stats);
    
    // Calculate ROI
    $spent = (float)$stats['total_spent'];
    $total_leads = (int)$stats['total_campaign_leads'];
    $cost_per_lead = $total_leads > 0 ? $spent / $total_leads : 0;
    
} catch (PDOException $e) {
    error_log("Error fetching campaign stats: " . $e->getMessage());
    $stats = [
        'total_campaigns' => 0,
        'active_campaigns' => 0,
        'inactive_campaigns' => 0,
        'total_budget' => 0,
        'total_spent' => 0,
        'total_campaign_leads' => 0,
        'converted_leads' => 0
    ];
    $cost_per_lead = 0;
}

// Build filter conditions
$where_conditions = ["c.company_id = ?"];
$params = [$company_id];

if (isset($_GET['is_active'])) {
    $where_conditions[] = "c.is_active = ?";
    $params[] = $_GET['is_active'];
}

if (!empty($_GET['type'])) {
    $where_conditions[] = "c.campaign_type = ?";
    $params[] = $_GET['type'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(c.campaign_name LIKE ? OR c.description LIKE ?)";
    $search = '%' . $_GET['search'] . '%';
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch campaigns
try {
    $campaigns_query = "
        SELECT 
            c.*,
            COUNT(DISTINCT l.lead_id) as lead_count,
            SUM(CASE WHEN l.status = 'converted' THEN 1 ELSE 0 END) as converted_count
        FROM campaigns c
        LEFT JOIN leads l ON c.campaign_id = l.campaign_id
        WHERE $where_clause
        GROUP BY c.campaign_id
        ORDER BY c.start_date DESC
    ";
    $stmt = $conn->prepare($campaigns_query);
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching campaigns: " . $e->getMessage());
    $campaigns = [];
}

$page_title = 'Marketing Campaigns';
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

.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.warning { border-left-color: #ffc107; }
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
    letter-spacing: 0.5px;
}

.campaign-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: transform 0.2s;
}

.campaign-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.campaign-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}

.campaign-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 15px;
}

.campaign-name {
    font-size: 1.2rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.campaign-type {
    background: #e9ecef;
    color: #495057;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background: #f8d7da;
    color: #721c24;
}

.campaign-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.stat-box {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 6px;
    text-align: center;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #007bff;
}

.stat-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
}

.progress-thin {
    height: 6px;
    border-radius: 3px;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-bullhorn text-info me-2"></i>Marketing Campaigns
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage and track marketing campaigns</p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="leads.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-users me-1"></i> Leads
                    </a>
                    <a href="create-campaign.php" class="btn btn-info">
                        <i class="fas fa-plus-circle me-1"></i> New Campaign
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
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card primary">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_campaigns']); ?></div>
                    <div class="stats-label">Total Campaigns</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card success">
                    <div class="stats-number"><?php echo number_format((int)$stats['active_campaigns']); ?></div>
                    <div class="stats-label">Active</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card warning">
                    <div class="stats-number"><?php echo number_format((int)$stats['total_campaign_leads']); ?></div>
                    <div class="stats-label">Leads Generated</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card info">
                    <div class="stats-number">TSH <?php echo number_format($cost_per_lead, 0); ?></div>
                    <div class="stats-label">Cost Per Lead</div>
                </div>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Search</label>
                        <input type="text" 
                               name="search" 
                               class="form-control" 
                               placeholder="Campaign name or description..."
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Campaign Type</label>
                        <select name="type" class="form-select">
                            <option value="">All Types</option>
                            <option value="email" <?php echo (isset($_GET['type']) && $_GET['type'] == 'email') ? 'selected' : ''; ?>>Email</option>
                            <option value="social_media" <?php echo (isset($_GET['type']) && $_GET['type'] == 'social_media') ? 'selected' : ''; ?>>Social Media</option>
                            <option value="ppc" <?php echo (isset($_GET['type']) && $_GET['type'] == 'ppc') ? 'selected' : ''; ?>>PPC</option>
                            <option value="event" <?php echo (isset($_GET['type']) && $_GET['type'] == 'event') ? 'selected' : ''; ?>>Event</option>
                            <option value="content" <?php echo (isset($_GET['type']) && $_GET['type'] == 'content') ? 'selected' : ''; ?>>Content Marketing</option>
                            <option value="other" <?php echo (isset($_GET['type']) && $_GET['type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Status</label>
                        <select name="is_active" class="form-select">
                            <option value="">All Status</option>
                            <option value="1" <?php echo (isset($_GET['is_active']) && $_GET['is_active'] == '1') ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo (isset($_GET['is_active']) && $_GET['is_active'] == '0') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="campaigns.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-1"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Campaigns Grid -->
        <?php if (empty($campaigns)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-bullhorn fa-4x mb-3 d-block"></i>
            <h4>No Campaigns Found</h4>
            <p class="mb-3">Start tracking your marketing efforts by creating your first campaign.</p>
            <a href="create-campaign.php" class="btn btn-info btn-lg">
                <i class="fas fa-plus-circle me-1"></i> Create First Campaign
            </a>
        </div>
        <?php else: ?>
        <div class="row">
            <?php foreach ($campaigns as $campaign): ?>
            <?php
            $budget = (float)$campaign['budget'];
            $spent = (float)$campaign['actual_cost'];
            $budget_used = $budget > 0 ? ($spent / $budget) * 100 : 0;
            $leads = (int)$campaign['lead_count'];
            $conversions = (int)$campaign['converted_count'];
            $conversion_rate = $leads > 0 ? ($conversions / $leads) * 100 : 0;
            ?>
            <div class="col-lg-6 col-xl-4">
                <div class="campaign-card">
                    <div class="campaign-header">
                        <div class="d-flex align-items-start">
                            <div class="campaign-icon">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div>
                                <div class="campaign-name"><?php echo htmlspecialchars($campaign['campaign_name']); ?></div>
                                <span class="campaign-type"><?php echo ucfirst(str_replace('_', ' ', $campaign['campaign_type'])); ?></span>
                            </div>
                        </div>
                        <span class="status-badge <?php echo $campaign['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $campaign['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <?php if (!empty($campaign['description'])): ?>
                    <p class="text-muted small mb-3">
                        <?php echo htmlspecialchars(substr($campaign['description'], 0, 100)) . (strlen($campaign['description']) > 100 ? '...' : ''); ?>
                    </p>
                    <?php endif; ?>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small">Budget Used</span>
                            <span class="small fw-bold"><?php echo number_format($budget_used, 1); ?>%</span>
                        </div>
                        <div class="progress progress-thin">
                            <div class="progress-bar bg-<?php echo $budget_used > 90 ? 'danger' : ($budget_used > 70 ? 'warning' : 'success'); ?>" 
                                 style="width: <?php echo min($budget_used, 100); ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">TSH <?php echo number_format($spent, 0); ?></small>
                            <small class="text-muted">/ TSH <?php echo number_format($budget, 0); ?></small>
                        </div>
                    </div>

                    <div class="campaign-stats">
                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($leads); ?></div>
                            <div class="stat-label">Leads</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($conversions); ?></div>
                            <div class="stat-label">Converted</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($conversion_rate, 1); ?>%</div>
                            <div class="stat-label">Conv. Rate</div>
                        </div>
                    </div>

                    <div class="mt-3 pt-3 border-top d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <?php 
                            if (!empty($campaign['start_date']) && !empty($campaign['end_date'])) {
                                echo date('M d', strtotime($campaign['start_date'])) . ' - ' . date('M d, Y', strtotime($campaign['end_date']));
                            } elseif (!empty($campaign['start_date'])) {
                                echo 'Started ' . date('M d, Y', strtotime($campaign['start_date']));
                            }
                            ?>
                        </small>
                        <div class="btn-group btn-group-sm">
                            <a href="view-campaign.php?id=<?php echo $campaign['campaign_id']; ?>" 
                               class="btn btn-outline-primary"
                               title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="edit-campaign.php?id=<?php echo $campaign['campaign_id']; ?>" 
                               class="btn btn-outline-warning"
                               title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php 
require_once '../../includes/footer.php';
?>