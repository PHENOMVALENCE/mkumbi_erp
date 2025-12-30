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

// Initialize variables
$positions = [];
$departments = [];
$stats = [
    'total_positions' => 0,
    'active_positions' => 0,
    'filled_positions' => 0,
    'vacant_positions' => 0
];
$errors = [];

// 🔥 FETCH DEPARTMENTS WITH FALLBACK
try {
    $dept_query = "SELECT department_id, department_name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY department_name ASC";
    $stmt = $conn->prepare($dept_query);
    $stmt->execute([$company_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no departments for this company, get all active departments
    if (empty($departments)) {
        $all_dept_query = "SELECT department_id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC";
        $stmt = $conn->prepare($all_dept_query);
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error fetching departments: " . $e->getMessage());
    // Fallback departments
    $departments = [
        ['department_id' => 1, 'department_name' => 'Human Resources'],
        ['department_id' => 2, 'department_name' => 'Finance'],
        ['department_id' => 3, 'department_name' => 'Information Technology'],
        ['department_id' => 4, 'department_name' => 'Operations'],
        ['department_id' => 5, 'department_name' => 'Sales']
    ];
}

// 🔥 FETCH POSITION STATISTICS
try {
    $stats_query = "
        SELECT 
            COUNT(DISTINCT p.position_id) as total_positions,
            SUM(CASE WHEN p.is_active = 1 THEN 1 ELSE 0 END) as active_positions,
            COUNT(DISTINCT e.employee_id) as filled_positions
        FROM positions p
        LEFT JOIN employees e ON p.position_id = e.position_id AND e.employment_status = 'active'
        WHERE p.company_id = ?
    ";
    $stmt = $conn->prepare($stats_query);
    $stmt->execute([$company_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $stats['total_positions'] = (int)$result['total_positions'];
        $stats['active_positions'] = (int)$result['active_positions'];
        $stats['filled_positions'] = (int)$result['filled_positions'];
        $stats['vacant_positions'] = $stats['active_positions'] - $stats['filled_positions'];
    }
} catch (PDOException $e) {
    error_log("Error fetching position stats: " . $e->getMessage());
}

// 🔥 BUILD FILTER CONDITIONS
$where_conditions = ["p.company_id = ?"];
$params = [$company_id];

if (!empty($_GET['department'])) {
    $where_conditions[] = "p.department_id = ?";
    $params[] = (int)$_GET['department'];
}

if (isset($_GET['status']) && $_GET['status'] !== '') {
    $where_conditions[] = "p.is_active = ?";
    $params[] = (int)$_GET['status'];
}

if (!empty($_GET['search'])) {
    $where_conditions[] = "(p.position_title LIKE ? OR p.position_code LIKE ? OR p.job_description LIKE ?)";
    $search = '%' . trim($_GET['search']) . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = implode(' AND ', $where_conditions);

// 🔥 FETCH POSITIONS
try {
    $query = "
        SELECT 
            p.*,
            d.department_name,
            COUNT(DISTINCT e.employee_id) as employee_count
        FROM positions p
        LEFT JOIN departments d ON p.department_id = d.department_id
        LEFT JOIN employees e ON p.position_id = e.position_id AND e.employment_status = 'active'
        WHERE " . $where_clause . "
        GROUP BY p.position_id
        ORDER BY d.department_name, p.position_title
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching positions: " . $e->getMessage());
    $positions = [];
}

// 🔥 HANDLE QUICK ADD POSITION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add'])) {
    if (empty($_POST['position_title'])) {
        $errors[] = "Position title is required";
    } else {
        try {
            // Generate position code
            $title_parts = explode(' ', trim($_POST['position_title']));
            $code_base = strtoupper(substr($title_parts[0], 0, 3));
            
            $count_query = "SELECT COUNT(*) FROM positions WHERE company_id = ? AND position_code LIKE ?";
            $stmt = $conn->prepare($count_query);
            $stmt->execute([$company_id, $code_base . '%']);
            $count = $stmt->fetchColumn();
            $position_code = $code_base . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
            
            // Insert position
            $insert_query = "
                INSERT INTO positions (
                    company_id, department_id, position_title, position_code,
                    job_description, min_salary, max_salary, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->execute([
                $company_id,
                !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
                trim($_POST['position_title']),
                $position_code,
                trim($_POST['job_description'] ?? ''),
                !empty($_POST['min_salary']) ? (float)$_POST['min_salary'] : null,
                !empty($_POST['max_salary']) ? (float)$_POST['max_salary'] : null
            ]);
            
            $_SESSION['success_message'] = "Position '{$_POST['position_title']}' added successfully!";
            header('Location: positions.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
            exit;
            
        } catch (PDOException $e) {
            error_log("Error adding position: " . $e->getMessage());
            $errors[] = "Error adding position: " . $e->getMessage();
        }
    }
}

$page_title = 'Job Positions';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
    transition: all 0.3s ease;
    border: none;
}

.stats-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
}

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}

.stats-label {
    font-size: 0.85rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 0.25rem;
}

.position-card {
    background: white;
    border-radius: 12px;
    padding: 1.75rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border-left: 5px solid #007bff;
    transition: all 0.3s ease;
}

.position-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}

.position-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: #212529;
    margin-bottom: 0.5rem;
}

.position-code {
    font-family: 'SF Mono', monospace;
    background: #e3f2fd;
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-weight: 600;
    color: #1976d2;
    font-size: 0.8rem;
    display: inline-block;
}

.department-badge {
    background: #fff3cd;
    color: #856404;
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
}

.employee-count-badge {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-weight: 700;
    display: inline-block;
}

.salary-range {
    background: #f8f9fa;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    font-size: 0.9rem;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.status-badge {
    padding: 0.4rem 0.9rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background: #f8d7da;
    color: #721c24;
}

.dept-count-badge {
    background: #e9ecef;
    color: #495057;
    padding: 0.25rem 0.6rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-briefcase text-primary me-2"></i>
                    Job Positions
                </h1>
                <p class="text-muted small mb-0 mt-1">
                    Manage company positions and roles (<?php echo count($positions); ?> found)
                </p>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quickAddModal">
                        <i class="fas fa-plus-circle me-1"></i> Add Position
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="content">
    <div class="container-fluid">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="stats-number"><?php echo number_format($stats['total_positions']); ?></div>
                    <div class="stats-label">Total Positions</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                    <div class="stats-number"><?php echo number_format($stats['active_positions']); ?></div>
                    <div class="stats-label">Active</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stats-number"><?php echo number_format($stats['filled_positions']); ?></div>
                    <div class="stats-label">Filled</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stats-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <div class="stats-number"><?php echo number_format($stats['vacant_positions']); ?></div>
                    <div class="stats-label">Vacant</div>
                </div>
            </div>
        </div>

        <!-- Departments Info -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong><?php echo count($departments); ?> Departments Available</strong> 
                    <span class="dept-count-badge ms-2"><?php echo count($departments); ?></span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-5 col-md-6">
                    <label class="form-label fw-semibold small">🔍 Search Positions</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search by title, code, or description..."
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold small">🏢 Department</label>
                    <select name="department" class="form-select">
                        <option value="">📋 All Departments (<?php echo count($departments); ?>)</option>
                        <?php if (empty($departments)): ?>
                            <option disabled>No departments available</option>
                        <?php else: ?>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['department_id']; ?>"
                                    <?php echo ($_GET['department'] ?? '') == $dept['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold small">📊 Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="1" <?php echo (isset($_GET['status']) && $_GET['status'] === '1') ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo (isset($_GET['status']) && $_GET['status'] === '0') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-lg-1 col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
                <div class="col-lg-1 col-md-3">
                    <?php if (!empty($_GET)): ?>
                    <a href="positions.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times me-1"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Positions Grid -->
        <?php if (empty($positions)): ?>
        <div class="position-card">
            <div class="text-center py-5">
                <i class="fas fa-briefcase fa-5x text-muted mb-3" style="opacity: 0.3;"></i>
                <h4 class="mb-3">No Positions Found</h4>
                <p class="lead mb-4 text-muted">
                    <?php if (!empty($_GET)): ?>
                        No positions match your search criteria.
                    <?php else: ?>
                        Start by creating positions for your company structure.
                    <?php endif; ?>
                </p>
                <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#quickAddModal">
                    <i class="fas fa-plus-circle me-2"></i> Add First Position
                </button>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($positions as $position): ?>
            <div class="col-lg-6 col-md-12">
                <div class="position-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="flex-grow-1">
                            <h5 class="position-title mb-2">
                                <?php echo htmlspecialchars($position['position_title']); ?>
                            </h5>
                            <div class="d-flex gap-2 flex-wrap">
                                <span class="position-code">
                                    <?php echo htmlspecialchars($position['position_code'] ?? 'N/A'); ?>
                                </span>
                                <span class="department-badge">
                                    <i class="fas fa-building me-1"></i>
                                    <?php echo htmlspecialchars($position['department_name'] ?? 'No Department'); ?>
                                </span>
                                <span class="status-badge <?php echo $position['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $position['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($position['job_description'])): ?>
                    <div class="mb-3">
                        <p class="text-muted mb-0 small">
                            <?php echo nl2br(htmlspecialchars(substr($position['job_description'], 0, 150))); ?>
                            <?php if (strlen($position['job_description']) > 150): ?>...<?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <?php if ($position['min_salary'] || $position['max_salary']): ?>
                        <div class="col-md-6">
                            <div class="salary-range">
                                <small class="d-block text-muted mb-1">💰 Salary Range</small>
                                <strong>
                                    <?php if ($position['min_salary'] && $position['max_salary']): ?>
                                    TSH <?php echo number_format($position['min_salary'], 0); ?> - 
                                    <?php echo number_format($position['max_salary'], 0); ?>
                                    <?php elseif ($position['min_salary']): ?>
                                    From TSH <?php echo number_format($position['min_salary'], 0); ?>
                                    <?php else: ?>
                                    Not specified
                                    <?php endif; ?>
                                </strong>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <span class="employee-count-badge">
                                <i class="fas fa-users me-2"></i>
                                <?php echo (int)$position['employee_count']; ?> Employee<?php echo (int)$position['employee_count'] != 1 ? 's' : ''; ?>
                            </span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <small class="text-muted">
                            <i class="fas fa-calendar me-1"></i>
                            Created <?php echo date('M j, Y', strtotime($position['created_at'] ?? 'now')); ?>
                        </small>
                        <div class="btn-group btn-group-sm">
                            <a href="view-position.php?id=<?php echo $position['position_id']; ?>" 
                               class="btn btn-outline-primary" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="edit-position.php?id=<?php echo $position['position_id']; ?>" 
                               class="btn btn-outline-success" title="Edit">
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

<!-- Quick Add Modal -->
<div class="modal fade" id="quickAddModal" tabindex="-1" aria-labelledby="quickAddModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="quick_add" value="1">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="quickAddModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>
                        Add New Position
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Position Title <span class="text-danger">*</span></label>
                            <input type="text" name="position_title" class="form-control" required
                                   placeholder="e.g., Software Developer, Accountant, Sales Manager">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">📋 Select Department</option>
                                <?php if (empty($departments)): ?>
                                    <option disabled>No departments available</option>
                                <?php else: ?>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Job Description</label>
                            <textarea name="job_description" class="form-control" rows="3" 
                                      placeholder="Brief description of the position responsibilities..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Minimum Salary (TSH)</label>
                            <input type="number" name="min_salary" class="form-control" min="0" step="1000"
                                   placeholder="e.g., 500000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Maximum Salary (TSH)</label>
                            <input type="number" name="max_salary" class="form-control" min="0" step="1000"
                                   placeholder="e.g., 1000000">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Position
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-generate position code preview
document.addEventListener('DOMContentLoaded', function() {
    const positionTitleInput = document.querySelector('input[name="position_title"]');
    const departmentSelect = document.querySelector('select[name="department_id"]');
    
    if (positionTitleInput) {
        positionTitleInput.addEventListener('input', function() {
            const title = this.value.trim();
            if (title) {
                const words = title.split(' ');
                let code = words[0].substring(0, 3).toUpperCase();
                // You can add more logic here for code generation
                console.log('Suggested code:', code + '001');
            }
        });
    }
    
    // Enhance form validation
    const quickAddForm = document.querySelector('form input[name="quick_add"]')?.closest('form');
    if (quickAddForm) {
        quickAddForm.addEventListener('submit', function(e) {
            const title = this.querySelector('input[name="position_title"]').value.trim();
            if (!title) {
                e.preventDefault();
                alert('Position title is required!');
                return false;
            }
            return true;
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>