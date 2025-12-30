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

// Handle template actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $sql = "INSERT INTO contract_templates (
                        company_id, template_name, contract_type, description,
                        template_content, placeholders, is_default, is_active, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $company_id,
                        $_POST['template_name'],
                        $_POST['template_type'],
                        $_POST['description'] ?? null,
                        $_POST['template_content'],
                        $_POST['variables'] ?? null,
                        isset($_POST['is_default']) ? 1 : 0,
                        $_SESSION['user_id']
                    ]);
                    
                    $_SESSION['success_message'] = "Contract template created successfully";
                    break;
                    
                case 'update':
                    $sql = "UPDATE contract_templates SET 
                        template_name = ?,
                        contract_type = ?,
                        description = ?,
                        template_content = ?,
                        placeholders = ?,
                        is_default = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE template_id = ? AND company_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $_POST['template_name'],
                        $_POST['template_type'],
                        $_POST['description'] ?? null,
                        $_POST['template_content'],
                        $_POST['variables'] ?? null,
                        isset($_POST['is_default']) ? 1 : 0,
                        $_POST['template_id'],
                        $company_id
                    ]);
                    
                    $_SESSION['success_message'] = "Contract template updated successfully";
                    break;
                    
                case 'toggle_status':
                    $sql = "UPDATE contract_templates 
                           SET is_active = NOT is_active 
                           WHERE template_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$_POST['template_id'], $company_id]);
                    
                    $_SESSION['success_message'] = "Template status updated";
                    break;
                    
                case 'delete':
                    $sql = "DELETE FROM contract_templates 
                           WHERE template_id = ? AND company_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$_POST['template_id'], $company_id]);
                    
                    $_SESSION['success_message'] = "Template deleted successfully";
                    break;
                    
                case 'duplicate':
                    $sql = "INSERT INTO contract_templates (
                        company_id, template_name, contract_type, description,
                        template_content, placeholders, is_default, is_active, created_by
                    )
                    SELECT 
                        company_id, 
                        CONCAT(template_name, ' (Copy)'),
                        contract_type,
                        description,
                        template_content,
                        placeholders,
                        0,
                        1,
                        ?
                    FROM contract_templates
                    WHERE template_id = ? AND company_id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$_SESSION['user_id'], $_POST['template_id'], $company_id]);
                    
                    $_SESSION['success_message'] = "Template duplicated successfully";
                    break;
            }
        }
        
        $conn->commit();
        header("Location: templates.php");
        exit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Template action error: " . $e->getMessage());
        $_SESSION['error_message'] = "Operation failed: " . $e->getMessage();
    }
}

// Get filter parameters
$type_filter = $_GET['type'] ?? 'all';
$status_filter = $_GET['status'] ?? 'active';
$search = $_GET['search'] ?? '';

// Fetch templates
$templates_sql = "SELECT 
    t.*,
    u.full_name as created_by_name,
    (SELECT COUNT(*) FROM reservations r WHERE r.contract_template_id = t.template_id) as usage_count
FROM contract_templates t
LEFT JOIN users u ON t.created_by = u.user_id
WHERE t.company_id = ?";

$params = [$company_id];

if ($type_filter !== 'all') {
    $templates_sql .= " AND t.contract_type = ?";
    $params[] = $type_filter;
}

if ($status_filter === 'active') {
    $templates_sql .= " AND t.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $templates_sql .= " AND t.is_active = 0";
}

if ($search) {
    $templates_sql .= " AND (t.template_name LIKE ? OR t.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$templates_sql .= " ORDER BY t.is_default DESC, t.template_name ASC";

try {
    $stmt = $conn->prepare($templates_sql);
    $stmt->execute($params);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching templates: " . $e->getMessage());
    $templates = [];
}

// Calculate statistics
$total_templates = count($templates);
$active_templates = count(array_filter($templates, fn($t) => $t['is_active'] == 1));
$default_templates = count(array_filter($templates, fn($t) => $t['is_default'] == 1));

// Get template for editing if ID provided
$edit_template = null;
if (isset($_GET['edit'])) {
    $edit_sql = "SELECT * FROM contract_templates 
                 WHERE template_id = ? AND company_id = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    $edit_stmt->execute([$_GET['edit'], $company_id]);
    $edit_template = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title = 'Contract Templates';
require_once '../../includes/header.php';
?>

<style>
.stats-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid;
    height: 100%;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-4px);
}

.stats-card.primary { border-left-color: #007bff; }
.stats-card.success { border-left-color: #28a745; }
.stats-card.info { border-left-color: #17a2b8; }

.stats-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2c3e50;
}

.stats-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    font-weight: 600;
}

.template-card {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    background: #fff;
    transition: all 0.2s;
    position: relative;
}

.template-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border-color: #007bff;
}

.template-card.inactive {
    opacity: 0.6;
    background: #f8f9fa;
}

.template-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 0.75rem;
}

.template-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.template-meta {
    font-size: 0.85rem;
    color: #6c757d;
}

.template-content-preview {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 0.75rem;
    font-size: 0.85rem;
    color: #495057;
    max-height: 100px;
    overflow: hidden;
    position: relative;
    margin-bottom: 0.75rem;
}

.template-content-preview:after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 30px;
    background: linear-gradient(transparent, #f8f9fa);
}

.badge-default {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.badge-type {
    background: #e9ecef;
    color: #495057;
    padding: 0.35rem 0.75rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.filter-card {
    background: #fff;
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.variable-tag {
    display: inline-block;
    background: #e3f2fd;
    color: #1976d2;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    font-size: 0.75rem;
    font-family: monospace;
    margin: 0.2rem;
}

.editor-toolbar {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    padding: 0.5rem;
}

.editor-toolbar button {
    margin-right: 0.25rem;
    padding: 0.25rem 0.5rem;
    font-size: 0.85rem;
}

#template_content {
    border-radius: 0 0 4px 4px;
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
}
</style>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-3 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0 fw-bold">
                    <i class="fas fa-file-contract text-primary me-2"></i>Contract Templates
                </h1>
                <p class="text-muted small mb-0 mt-1">Manage contract and agreement templates</p>
            </div>
            <div class="col-sm-6 text-end">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal">
                    <i class="fas fa-plus-circle me-1"></i>Create Template
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- Display Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-4">
            <div class="stats-card primary">
                <div class="stats-number"><?= number_format($total_templates) ?></div>
                <div class="stats-label">Total Templates</div>
            </div>
        </div>
        <div class="col-lg-4 col-md-4">
            <div class="stats-card success">
                <div class="stats-number"><?= number_format($active_templates) ?></div>
                <div class="stats-label">Active Templates</div>
            </div>
        </div>
        <div class="col-lg-4 col-md-4">
            <div class="stats-card info">
                <div class="stats-number"><?= number_format($default_templates) ?></div>
                <div class="stats-label">Default Templates</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Template Type</label>
                <select name="type" class="form-select">
                    <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="sale_agreement" <?= $type_filter === 'sale_agreement' ? 'selected' : '' ?>>Sale Agreement</option>
                    <option value="reservation_agreement" <?= $type_filter === 'reservation_agreement' ? 'selected' : '' ?>>Reservation Agreement</option>
                    <option value="installment_plan" <?= $type_filter === 'installment_plan' ? 'selected' : '' ?>>Installment Plan</option>
                    <option value="lease_agreement" <?= $type_filter === 'lease_agreement' ? 'selected' : '' ?>>Lease Agreement</option>
                    <option value="other" <?= $type_filter === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="col-md-5">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="search" class="form-control" 
                       placeholder="Template name or description..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                    <a href="templates.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Templates List -->
    <?php if (empty($templates)): ?>
        <div class="text-center py-5">
            <i class="fas fa-file-contract fa-3x text-muted mb-3"></i>
            <p class="text-muted mb-3">No contract templates found</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal">
                <i class="fas fa-plus-circle me-1"></i>Create Your First Template
            </button>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($templates as $template): ?>
                <div class="col-lg-6">
                    <div class="template-card <?= $template['is_active'] ? '' : 'inactive' ?>">
                        <div class="template-header">
                            <div style="flex: 1;">
                                <div class="template-title">
                                    <?= htmlspecialchars($template['template_name']) ?>
                                    <?php if ($template['is_default']): ?>
                                        <span class="badge badge-default ms-2">
                                            <i class="fas fa-star"></i> Default
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!$template['is_active']): ?>
                                        <span class="badge bg-secondary ms-2">Inactive</span>
                                    <?php endif; ?>
                                </div>
                                <div class="template-meta">
                                    <span class="badge-type"><?= ucwords(str_replace('_', ' ', $template['contract_type'])) ?></span>
                                    <span class="ms-2">
                                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($template['created_by_name']) ?>
                                    </span>
                                    <span class="ms-2">
                                        <i class="fas fa-clock me-1"></i><?= date('M d, Y', strtotime($template['created_at'])) ?>
                                    </span>
                                    <?php if ($template['usage_count'] > 0): ?>
                                        <span class="ms-2 text-success">
                                            <i class="fas fa-check-circle me-1"></i>Used <?= $template['usage_count'] ?> time(s)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($template['description']): ?>
                            <div class="mb-2">
                                <small class="text-muted"><?= htmlspecialchars($template['description']) ?></small>
                            </div>
                        <?php endif; ?>

                        <div class="template-content-preview">
                            <?= nl2br(htmlspecialchars(substr($template['template_content'], 0, 200))) ?>...
                        </div>

                        <?php if ($template['placeholders']): ?>
                            <div class="mb-2">
                                <small class="text-muted fw-bold">Variables:</small><br>
                                <?php
                                $vars = json_decode($template['placeholders'], true);
                                if ($vars && is_array($vars)) {
                                    foreach ($vars as $var) {
                                        echo '<span class="variable-tag">{' . htmlspecialchars($var) . '}</span>';
                                    }
                                }
                                ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2">
                            <a href="?edit=<?= $template['template_id'] ?>" 
                               class="btn btn-sm btn-outline-primary"
                               onclick="loadTemplateForEdit(<?= $template['template_id'] ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="duplicate">
                                <input type="hidden" name="template_id" value="<?= $template['template_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-copy"></i> Duplicate
                                </button>
                            </form>

                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="template_id" value="<?= $template['template_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-<?= $template['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                                    <?= $template['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>

                            <button type="button" 
                                    class="btn btn-sm btn-outline-info"
                                    onclick="previewTemplate(<?= $template['template_id'] ?>)">
                                <i class="fas fa-eye"></i> Preview
                            </button>

                            <?php if ($template['usage_count'] == 0): ?>
                                <form method="POST" class="d-inline" 
                                      onsubmit="return confirm('Delete this template permanently?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="template_id" value="<?= $template['template_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Create/Edit Template Modal -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <span id="modalTitle">Create New Template</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="templateForm">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="template_id" id="template_id">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Template Name <span class="text-danger">*</span></label>
                                <input type="text" name="template_name" id="template_name" 
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Template Type <span class="text-danger">*</span></label>
                                <select name="template_type" id="template_type" class="form-select" required>
                                    <option value="">Select Type</option>
                                    <option value="sale_agreement">Sale Agreement</option>
                                    <option value="reservation_agreement">Reservation Agreement</option>
                                    <option value="installment_plan">Installment Plan</option>
                                    <option value="lease_agreement">Lease Agreement</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <input type="text" name="description" id="description" 
                               class="form-control" 
                               placeholder="Brief description of this template">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Template Content <span class="text-danger">*</span></label>
                        
                        <!-- Editor Toolbar -->
                        <div class="editor-toolbar">
                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                    onclick="insertVariable('customer_name')">
                                {customer_name}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                    onclick="insertVariable('plot_number')">
                                {plot_number}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                    onclick="insertVariable('project_name')">
                                {project_name}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                    onclick="insertVariable('total_amount')">
                                {total_amount}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                    onclick="insertVariable('reservation_date')">
                                {reservation_date}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                    onclick="insertVariable('current_date')">
                                {current_date}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                    onclick="insertVariable('company_name')">
                                {company_name}
                            </button>
                        </div>
                        
                        <textarea name="template_content" id="template_content" 
                                  class="form-control" rows="15" required
                                  placeholder="Enter your contract template here. Use {variable_name} for dynamic content."></textarea>
                        
                        <small class="text-muted">
                            <strong>Available Variables:</strong> 
                            {customer_name}, {customer_phone}, {customer_email}, {customer_address},
                            {plot_number}, {plot_size}, {block_number}, {project_name}, 
                            {total_amount}, {deposit_amount}, {balance}, 
                            {reservation_date}, {reservation_number}, {current_date}, 
                            {company_name}, {company_address}, {company_phone}
                        </small>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" 
                               name="is_default" id="is_default" value="1">
                        <label class="form-check-label" for="is_default">
                            <strong>Set as Default Template</strong>
                            <small class="text-muted d-block">This template will be pre-selected for new contracts</small>
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i><span id="submitBtnText">Create Template</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Template Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="previewContent" style="white-space: pre-wrap; font-family: 'Times New Roman', serif; line-height: 1.6;">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function insertVariable(varName) {
    const textarea = document.getElementById('template_content');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const variable = '{' + varName + '}';
    
    textarea.value = text.substring(0, start) + variable + text.substring(end);
    textarea.selectionStart = textarea.selectionEnd = start + variable.length;
    textarea.focus();
}

function loadTemplateForEdit(templateId) {
    // This would load template data via AJAX
    document.getElementById('modalTitle').textContent = 'Edit Template';
    document.getElementById('formAction').value = 'update';
    document.getElementById('submitBtnText').textContent = 'Update Template';
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('templateModal'));
    modal.show();
}

function previewTemplate(templateId) {
    // Fetch and show preview
    fetch('preview_template.php?id=' + templateId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('previewContent').innerHTML = html;
            const modal = new bootstrap.Modal(document.getElementById('previewModal'));
            modal.show();
        });
}

// Reset form when modal closes
document.getElementById('templateModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('templateForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('modalTitle').textContent = 'Create New Template';
    document.getElementById('submitBtnText').textContent = 'Create Template';
    document.getElementById('template_id').value = '';
});

<?php if ($edit_template): ?>
// Auto-load edit data if editing
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('template_id').value = '<?= $edit_template['template_id'] ?>';
    document.getElementById('template_name').value = '<?= addslashes($edit_template['template_name']) ?>';
    document.getElementById('template_type').value = '<?= $edit_template['contract_type'] ?>';
    document.getElementById('description').value = '<?= addslashes($edit_template['description'] ?? '') ?>';
    document.getElementById('template_content').value = `<?= addslashes($edit_template['template_content']) ?>`;
    document.getElementById('is_default').checked = <?= $edit_template['is_default'] ? 'true' : 'false' ?>;
    
    document.getElementById('formAction').value = 'update';
    document.getElementById('modalTitle').textContent = 'Edit Template';
    document.getElementById('submitBtnText').textContent = 'Update Template';
    
    const modal = new bootstrap.Modal(document.getElementById('templateModal'));
    modal.show();
});
<?php endif; ?>
</script>

<?php require_once '../../includes/footer.php'; ?>