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

$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$success_message = '';

if ($project_id <= 0) {
    $_SESSION['error_message'] = "Invalid project ID";
    header('Location: projects.php');
    exit;
}

// Fetch current project data
$project = [];
try {
    $stmt = $conn->prepare("SELECT * FROM projects WHERE project_id = ? AND company_id = ? AND is_active = 1");
    $stmt->execute([$project_id, $company_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        $_SESSION['error_message'] = "Project not found";
        header('Location: projects.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching project: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading project data";
    header('Location: projects.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_name = trim($_POST['project_name']);
    $project_code = trim($_POST['project_code']);
    $location = trim($_POST['location']);
    $project_type = trim($_POST['project_type']);
    $description = trim($_POST['description']);
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $expected_completion = !empty($_POST['expected_completion']) ? $_POST['expected_completion'] : null;
    $road_access = isset($_POST['road_access']) ? 1 : 0;
    $utilities_available = isset($_POST['utilities_available']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if (empty($project_name)) {
        $errors[] = "Project name is required";
    }

    if (empty($project_code)) {
        $errors[] = "Project code is required";
    }

    if (empty($location)) {
        $errors[] = "Location is required";
    }

    // Check if project code already exists (excluding current project)
    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("SELECT project_id FROM projects WHERE project_code = ? AND company_id = ? AND project_id != ? AND is_active = 1");
            $stmt->execute([$project_code, $company_id, $project_id]);
            if ($stmt->fetch()) {
                $errors[] = "Project code already exists";
            }
        } catch (PDOException $e) {
            error_log("Error checking project code: " . $e->getMessage());
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $update_query = "
                UPDATE projects SET 
                    project_name = ?,
                    project_code = ?,
                    location = ?,
                    project_type = ?,
                    description = ?,
                    start_date = ?,
                    expected_completion = ?,
                    road_access = ?,
                    utilities_available = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE project_id = ? AND company_id = ?
            ";

            $stmt = $conn->prepare($update_query);
            $stmt->execute([
                $project_name,
                $project_code,
                $location,
                $project_type ?: null,
                $description ?: null,
                $start_date,
                $expected_completion,
                $road_access,
                $utilities_available,
                $is_active,
                $project_id,
                $company_id
            ]);

            $conn->commit();
            $_SESSION['success_message'] = "Project updated successfully!";
            header('Location: view.php?id=' . $project_id);
            exit;

        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Error updating project: " . $e->getMessage());
            $errors[] = "Error updating project. Please try again.";
        }
    }
}

$page_title = 'Edit Project - ' . $project['project_name'];
require_once '../../includes/header.php';
?>

<style>
.form-section {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
    margin-bottom: 1.5rem;
}

.form-section h5 {
    color: #007bff;
    font-weight: 700;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
}

.required-indicator {
    color: #dc3545;
    font-weight: bold;
}

.form-help-text {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.date-picker-container {
    position: relative;
}

.date-picker-container i {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    z-index: 10;
}

.project-type-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.project-type-tag {
    background: #e9ecef;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.project-type-tag:hover,
.project-type-tag.active {
    background: #007bff;
    color: white;
}

.status-toggle {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.toggle-switch {
    position: relative;
    width: 60px;
    height: 30px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 30px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 24px;
    width: 24px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #28a745;
}

input:checked + .slider:before {
    transform: translateX(30px);
}
</style>

<!-- Content Header -->
<div class="content-header mb-4">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold text-primary">
                    <i class="fas fa-edit me-2"></i>
                    Edit Project
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="projects.php">Projects</a></li>
                        <li class="breadcrumb-item"><a href="view.php?id=<?php echo $project_id; ?>">View</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit</li>
                    </ol>
                </nav>
            </div>
            <div class="col-sm-6">
                <div class="float-sm-end">
                    <a href="view.php?id=<?php echo $project_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-eye me-1"></i> View Project
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
            <ul class="mb-0 mt-3">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="editProjectForm">
            
            <!-- Basic Information -->
            <div class="form-section">
                <h5><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Project Code <span class="required-indicator">*</span></label>
                        <input type="text" name="project_code" class="form-control" required maxlength="20"
                               value="<?php echo htmlspecialchars($project['project_code'] ?? ''); ?>"
                               placeholder="e.g., MJP001">
                        <div class="form-help-text">Unique project identifier (Max 20 characters)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Project Name <span class="required-indicator">*</span></label>
                        <input type="text" name="project_name" class="form-control" required maxlength="100"
                               value="<?php echo htmlspecialchars($project['project_name'] ?? ''); ?>"
                               placeholder="e.g., Mikocheni Gardens Phase 1">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Location <span class="required-indicator">*</span></label>
                        <input type="text" name="location" class="form-control" required maxlength="200"
                               value="<?php echo htmlspecialchars($project['location'] ?? ''); ?>"
                               placeholder="e.g., Mikocheni, Kinondoni, Dar es Salaam">
                    </div>
                </div>
            </div>

            <!-- Project Details -->
            <div class="form-section" style="border-left-color: #28a745;">
                <h5 style="color: #28a745;"><i class="fas fa-cogs me-2"></i>Project Details</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Project Type</label>
                        <input type="text" name="project_type" class="form-control" maxlength="50"
                               value="<?php echo htmlspecialchars($project['project_type'] ?? ''); ?>"
                               placeholder="e.g., Residential, Commercial, Mixed-Use">
                        <div class="project-type-tags">
                            <span class="project-type-tag" data-type="Residential">Residential</span>
                            <span class="project-type-tag" data-type="Commercial">Commercial</span>
                            <span class="project-type-tag" data-type="Mixed-Use">Mixed-Use</span>
                            <span class="project-type-tag" data-type="Industrial">Industrial</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" maxlength="1000"
                                  placeholder="Brief description of the project"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                        <div class="form-help-text">Optional: Describe project features, amenities, etc.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Start Date</label>
                        <div class="date-picker-container">
                            <input type="date" name="start_date" class="form-control" 
                                   value="<?php echo $project['start_date'] ?? ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Expected Completion</label>
                        <div class="date-picker-container">
                            <input type="date" name="expected_completion" class="form-control"
                                   value="<?php echo $project['expected_completion'] ?? ''; ?>">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Infrastructure -->
            <div class="form-section" style="border-left-color: #17a2b8;">
                <h5 style="color: #17a2b8;"><i class="fas fa-road me-2"></i>Infrastructure & Utilities</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="road_access" id="road_access"
                                   value="1" <?php echo $project['road_access'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="road_access">
                                <i class="fas fa-road me-2 text-info"></i>
                                Road Access Available
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="utilities_available" id="utilities_available"
                                   value="1" <?php echo $project['utilities_available'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="utilities_available">
                                <i class="fas fa-plug me-2 text-success"></i>
                                Utilities Available (Water, Electricity, etc.)
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <div class="form-section" style="border-left-color: #6f42c1;">
                <h5 style="color: #6f42c1;"><i class="fas fa-toggle-on me-2"></i>Project Status</h5>
                <div class="row g-3 align-items-center">
                    <div class="col-md-8">
                        <div class="status-toggle">
                            <label class="form-label me-3">Project Status:</label>
                            <label class="switch">
                                <input type="checkbox" name="is_active" id="is_active" 
                                       <?php echo $project['is_active'] ? 'checked' : ''; ?> value="1">
                                <span class="slider"></span>
                            </label>
                            <span class="ms-3 fs-6 fw-semibold" id="statusText">
                                <?php echo $project['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="form-help-text mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Inactive projects will be hidden from plot listings
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-section" style="border-left-color: #6c757d; background: #f8f9fa;">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Important:</strong> Updating project details will affect all associated plots. 
                            Project code must be unique across all projects.
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="view.php?id=<?php echo $project_id; ?>" class="btn btn-outline-secondary btn-lg px-4 me-2">
                            <i class="fas fa-times me-2"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                            <i class="fas fa-save me-2"></i> Update Project
                        </button>
                    </div>
                </div>
            </div>

        </form>

    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editProjectForm');
    const projectTypeInput = document.querySelector('input[name="project_type"]');
    const projectTypeTags = document.querySelectorAll('.project-type-tag');
    const isActiveToggle = document.getElementById('is_active');
    const statusText = document.getElementById('statusText');
    const submitBtn = document.getElementById('submitBtn');

    // Project type tags functionality
    projectTypeTags.forEach(tag => {
        tag.addEventListener('click', function() {
            projectTypeTags.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            projectTypeInput.value = this.dataset.type;
        });
    });

    // Match current value
    if (projectTypeInput.value) {
        projectTypeTags.forEach(tag => {
            if (tag.dataset.type === projectTypeInput.value) {
                tag.classList.add('active');
            }
        });
    }

    // Status toggle
    isActiveToggle.addEventListener('change', function() {
        statusText.textContent = this.checked ? 'Active' : 'Inactive';
        statusText.className = `ms-3 fs-6 fw-semibold text-${this.checked ? 'success' : 'danger'}`;
    });

    // Form validation and submission
    form.addEventListener('submit', function(e) {
        const requiredFields = ['project_code', 'project_name', 'location'];
        let isValid = true;

        requiredFields.forEach(field => {
            const input = document.querySelector(`[name="${field}"]`);
            if (!input.value.trim()) {
                input.classList.add('is-invalid');
                isValid = false;
            } else {
                input.classList.remove('is-invalid');
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please fill all required fields marked with *');
            return false;
        }

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating Project...';
    });

    // Date picker enhancement
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(input => {
        input.addEventListener('change', function() {
            if (this.value) {
                this.classList.add('is-valid');
            } else {
                this.classList.remove('is-valid');
            }
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>